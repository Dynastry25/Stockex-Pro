<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../tcpdf/tcpdf.php';
require_once __DIR__ . '/../reports/traits/ReportHeaderTrait.php';

require_trader();
require_mandate();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$dealing_sheet_enabled = false;
$success_message = '';
$error_message = '';

if (function_exists('dealingSheetEnsureSchema')) {
    try {
        dealingSheetEnsureSchema($db);
        $dealing_sheet_enabled = true;
    } catch (Exception $e) {
        error_log('Unable to initialize dealing sheet schema on trades page: ' . $e->getMessage());
    }
}

function syncTradeToDealingSheetSafely($db, $tradeId, $user, $createIfMissing = false)
{
    if (!function_exists('dealingSheetSyncTradeLifecycle')) {
        return;
    }

    try {
        dealingSheetSyncTradeLifecycle($db, (int) $tradeId, $user, $createIfMissing);
    } catch (Exception $e) {
        error_log('Failed to sync trade ' . (int) $tradeId . ' to dealing sheet lifecycle: ' . $e->getMessage());
    }
}

function markTradeContractNoteGeneratedSafely($db, $tradeId, $user, $createIfMissing = false)
{
    if (!function_exists('dealingSheetMarkContractGeneratedForTrade')) {
        return;
    }

    try {
        dealingSheetMarkContractGeneratedForTrade($db, (int) $tradeId, $user, $createIfMissing);
    } catch (Exception $e) {
        error_log('Failed to mark contract note generation for trade ' . (int) $tradeId . ': ' . $e->getMessage());
    }
}

function buildTradeListUrl($overrides = []) {
    $query = $_GET;
    unset($query['ajax']);

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }

        $query[$key] = $value;
    }

    $queryString = http_build_query($query);

    return $queryString ? '?' . $queryString : '?';
}

// Get company details from database
$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';
$company_phone = $company ? $company['phone'] : '+255 746 177 230';
$company_address = $company ? $company['address'] : 'P.O BOX 36098 Kigamboni, Dar es Salaam';
$company_email = $company ? $company['email'] : 'info@neovam.com';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trades_export_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    
    $headers = [
        'Reference', 'Instrument', 'Asset Type', 'Side', 'Quantity', 
        'Price', 'Total Value', 'Client', 'Client Account', 'Counterparty', 
        'Counterparty Account', 'Trade Date', 'Settlement Date', 'Status', 
        'Commission Type', 'Commission Rate (%)', 'Liberty Mode', 'Commission Amount', 'Created At'
    ];
    fputcsv($output, $headers, ',', '"', '\\');
    
    $where_conditions = [];
    $params = [];
    
    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $where_conditions[] = "t.status = ?";
        $params[] = $_GET['status'];
    }
    if (isset($_GET['type']) && !empty($_GET['type'])) {
        $where_conditions[] = "t.asset_class = ?";
        $params[] = $_GET['type'];
    }
    if (isset($_GET['trade_date_from']) && !empty($_GET['trade_date_from'])) {
        $where_conditions[] = "t.trade_date >= ?";
        $params[] = $_GET['trade_date_from'];
    }
    if (isset($_GET['trade_date_to']) && !empty($_GET['trade_date_to'])) {
        $where_conditions[] = "t.trade_date <= ?";
        $params[] = $_GET['trade_date_to'];
    }
    if (isset($_GET['settlement_date_from']) && !empty($_GET['settlement_date_from'])) {
        $where_conditions[] = "t.settlement_date >= ?";
        $params[] = $_GET['settlement_date_from'];
    }
    if (isset($_GET['settlement_date_to']) && !empty($_GET['settlement_date_to'])) {
        $where_conditions[] = "t.settlement_date <= ?";
        $params[] = $_GET['settlement_date_to'];
    }
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search_term = '%' . $_GET['search'] . '%';
        $where_conditions[] = "(t.trade_reference LIKE ? OR t.security_id LIKE ? OR t.client_name LIKE ? OR t.counterparty_name LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (empty($where_conditions)) {
        $where_clause = "1=1";
    } else {
        $where_clause = implode(' AND ', $where_conditions);
    }
    
    $export_stmt = $db->prepare("
        SELECT t.*, cl.default_brokerage_fee, cl.fee_type as client_fee_type, cl.liberty_mode as client_liberty_mode
        FROM trades t
        LEFT JOIN clients cl ON t.client_name = cl.client_name
        WHERE $where_clause
        ORDER BY t.created_at DESC
    ");
    $export_stmt->execute($params);
    $export_trades = $export_stmt->fetchAll();
    
    foreach ($export_trades as $trade) {
        $commission_rate = '';
        $liberty_mode_display = '';
        if ($trade['brokerage_fee_type'] == 'normal') {
            $commission_rate = 'Standard';
            $liberty_mode_display = 'N/A';
        } elseif ($trade['brokerage_fee_type'] == 'liberty') {
            $commission_rate = $trade['default_brokerage_fee'] ?? 'Liberty';
            $liberty_mode_display = $trade['liberty_mode'] ?? ($trade['client_liberty_mode'] ?? 'replace_all');
            $liberty_mode_display = ($liberty_mode_display == 'replace_all') ? 'Full Rate' : (($liberty_mode_display == 'excess_only') ? 'Excess Only' : 'Tier Override');
        } elseif ($trade['brokerage_fee_type'] == 'this_trade') {
            $commission_rate = $trade['custom_brokerage_fee'] ?? 'Custom';
            $liberty_mode_display = $trade['liberty_mode'] ?? 'replace_all';
            $liberty_mode_display = ($liberty_mode_display == 'replace_all') ? 'Full Rate' : (($liberty_mode_display == 'excess_only') ? 'Excess Only' : 'Tier Override');
        }
        
        $row = [
            $trade['trade_reference'],
            $trade['security_id'],
            $trade['asset_class'] === 'Exchange Traded Funds' ? 'ETF' : ucfirst($trade['asset_class']),
            ucfirst($trade['trade_side']),
            $trade['quantity'],
            number_format($trade['price'], 2),
            number_format($trade['consideration'], 2),
            $trade['client_name'],
            $trade['client_cds_account'],
            $trade['counterparty_name'],
            $trade['counterparty_cds_account'],
            $trade['trade_date'],
            $trade['settlement_date'],
            ucfirst($trade['status']),
            ucfirst($trade['brokerage_fee_type']),
            $commission_rate,
            $liberty_mode_display,
            number_format($trade['final_brokerage_fee'], 2),
            $trade['created_at']
        ];
        fputcsv($output, $row, ',', '"', '\\');
    }
    
    fclose($output);
    exit;
}

// ============ BOND CALCULATION FUNCTIONS ============

// Standard bond brokerage fee with tiered structure
function calculateBondBrokerageFee($face_value) {
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
    return $brokerage_first_100m + $brokerage_excess;
}

// Bond: Liberty applies to excess only (first 100M uses standard rate)
function calculateBondBrokerageWithLibertyExcess($face_value, $liberty_rate) {
    $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
    $brokerage_excess = max($face_value - 100000000, 0) * ($liberty_rate / 100);
    return $brokerage_first_100m + $brokerage_excess;
}

// Bond: Liberty replaces entire calculation (simple percentage)
function calculateBondBrokerageWithLibertyReplaceAll($face_value, $liberty_rate) {
    return $face_value * ($liberty_rate / 100);
}

// ============ EQUITY CALCULATION FUNCTIONS ============

// Standard equity brokerage fee with tiered structure
function calculateEquityBrokerageFee($consideration, $tier1_rate = 1.7, $tier2_rate = 1.5, $tier3_rate = 0.8) {
    if ($consideration <= 10000000) {
        return $consideration * ($tier1_rate / 100);
    } elseif ($consideration <= 50000000) {
        return 10000000 * ($tier1_rate / 100) + ($consideration - 10000000) * ($tier2_rate / 100);
    } else {
        return 10000000 * ($tier1_rate / 100) + 40000000 * ($tier2_rate / 100) + ($consideration - 50000000) * ($tier3_rate / 100);
    }
}

// Equity: Liberty replaces entire calculation (simple percentage)
function calculateEquityBrokerageWithLibertyReplaceAll($consideration, $liberty_rate) {
    return $consideration * ($liberty_rate / 100);
}

// Equity: Liberty applies to tier override (first 10M standard, excess liberty)
function calculateEquityBrokerageWithLibertyTierOverride($consideration, $liberty_rate, $standard_rate) {
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

// ============ MAIN BROKERAGE CALCULATION FUNCTION ============

function calculateBrokerageFee($trade, $rate_percentage, $liberty_mode = 'replace_all', $standard_rate = null) {
    global $standard_rate_percentage;
    
    if ($standard_rate === null) {
        $standard_rate = $standard_rate_percentage ?? 1.7;
    }
    
    $is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
    
    if ($is_bond) {
        $face_value = floatval($trade['quantity']);
        
        switch ($liberty_mode) {
            case 'excess_only':
                return calculateBondBrokerageWithLibertyExcess($face_value, $rate_percentage);
            case 'replace_all':
                return calculateBondBrokerageWithLibertyReplaceAll($face_value, $rate_percentage);
            default:
                return calculateBondBrokerageFee($face_value);
        }
    } else {
        $consideration = floatval($trade['consideration']);
        
        switch ($liberty_mode) {
            case 'tier_override':
                return calculateEquityBrokerageWithLibertyTierOverride($consideration, $rate_percentage, $standard_rate);
            case 'replace_all':
                return calculateEquityBrokerageWithLibertyReplaceAll($consideration, $rate_percentage);
            default:
                return calculateEquityBrokerageFee($consideration);
        }
    }
}

// Function to get effective brokerage rate for a trade - FIXED for liberty
function getEffectiveBrokerageRate($db, $trade, $client) {
    // Debug log
    error_log("getEffectiveBrokerageRate: Trade ID {$trade['id']}, Fee Type: {$trade['brokerage_fee_type']}");
    
    // First check if this is a liberty trade
    if ($trade['brokerage_fee_type'] === 'liberty') {
        // Get the client's liberty rate from the clients table
        $stmt = $db->prepare("SELECT default_brokerage_fee FROM clients WHERE cds_account = ? AND fee_type = 'liberty'");
        $stmt->execute([$trade['client_cds_account']]);
        $liberty_rate = $stmt->fetchColumn();
        
        if ($liberty_rate !== false && $liberty_rate > 0) {
            error_log("getEffectiveBrokerageRate: Found liberty rate {$liberty_rate} for client {$trade['client_cds_account']}");
            return floatval($liberty_rate);
        }
        
        // Fallback to trade's custom brokerage fee if available
        if (!empty($trade['custom_brokerage_fee']) && $trade['custom_brokerage_fee'] > 0) {
            error_log("getEffectiveBrokerageRate: Using trade custom rate {$trade['custom_brokerage_fee']}");
            return floatval($trade['custom_brokerage_fee']);
        }
    }
    
    if ($trade['brokerage_fee_type'] === 'this_trade') {
        if (!empty($trade['custom_brokerage_fee']) && $trade['custom_brokerage_fee'] > 0) {
            error_log("getEffectiveBrokerageRate: This trade custom rate {$trade['custom_brokerage_fee']}");
            return floatval($trade['custom_brokerage_fee']);
        }
    }
    
    if ($trade['brokerage_fee_type'] === 'normal') {
        $asset_class = strtoupper($trade['asset_class']);
        if ($asset_class === 'EXCHANGE TRADED FUNDS') {
            $asset_class = 'ETF';
        }
        
        $stmt_fee = $db->prepare("
            SELECT rate_percentage FROM fee_configurations
            WHERE fee_type = 'BROKERAGE' AND applies_to = ? AND is_active = 1
        ");
        $stmt_fee->execute([$asset_class]);
        $rate = $stmt_fee->fetchColumn();
        
        if (!$rate) {
            $stmt_fee = $db->prepare("
                SELECT rate_percentage FROM fee_configurations
                WHERE fee_type = 'BROKERAGE' AND applies_to = 'ALL' AND is_active = 1
            ");
            $stmt_fee->execute();
            $rate = $stmt_fee->fetchColumn();
        }
        
        error_log("getEffectiveBrokerageRate: Standard rate {$rate} for asset class {$asset_class}");
        return $rate ?: 0;
    }
    
    return 0;
}

// Function to calculate bond fees for contract note - FIXED for liberty rate display
function calculateBondFeesForContract($face_value, $consideration, $effective_rate = null, $liberty_mode = 'replace_all', $is_liberty = false, $trade_side = 'Sell') {
    $fees = [];
    
    // Debug logging
    error_log("calculateBondFeesForContract: face_value={$face_value}, effective_rate={$effective_rate}, liberty_mode={$liberty_mode}, is_liberty=" . ($is_liberty ? 'true' : 'false'));
    
    if ($is_liberty && $effective_rate !== null && $effective_rate > 0) {
        if ($liberty_mode === 'excess_only') {
            // Liberty on excess only
            $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
            $brokerage_excess = max($face_value - 100000000, 0) * ($effective_rate / 100);
            $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
            
            $fees['tier_details'] = [];
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
                    'label' => 'Excess ' . number_format(($face_value - 100000000)/1000000, 2) . 'M @ ' . number_format($effective_rate, 4) . '% (Liberty)'
                ];
            } else {
                $fees['tier_details'][] = [
                    'amount' => $face_value,
                    'rate' => 0.063132,
                    'fee' => $brokerage_first_100m,
                    'label' => 'First ' . number_format($face_value/1000000, 2) . 'M @ 0.063132%'
                ];
            }
        } else {
            // Liberty replace all - Full liberty rate on entire face value
            $fees['brokerage'] = $face_value * ($effective_rate / 100);
            $fees['tier_details'][] = [
                'amount' => $face_value,
                'rate' => $effective_rate,
                'fee' => $fees['brokerage'],
                'label' => 'Full ' . number_format($face_value/1000000, 2) . 'M @ ' . number_format($effective_rate, 4) . '% (Liberty)'
            ];
        }
    } else {
        // Standard tiered calculation (no liberty)
        $brokerage_first_100m = min($face_value, 100000000) * (0.063132 / 100);
        $brokerage_excess = max($face_value - 100000000, 0) * (0.035 / 100);
        $fees['brokerage'] = $brokerage_first_100m + $brokerage_excess;
        
        $fees['tier_details'] = [];
        if ($face_value <= 100000000) {
            $fees['tier_details'][] = [
                'amount' => $face_value,
                'rate' => 0.063132,
                'fee' => $brokerage_first_100m,
                'label' => 'First ' . number_format($face_value/1000000, 2) . 'M @ 0.063132%'
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
                'label' => 'Excess ' . number_format(($face_value - 100000000)/1000000, 2) . 'M @ 0.035%'
            ];
        }
    }
    
    // VAT on Brokerage (18%)
    $fees['vat'] = $fees['brokerage'] * 0.18;
    
    // CMSA Fee - Based on Consideration, rate 0.01%
    $fees['cmsa'] = $consideration * (0.01 / 100);
    
    // CDS Fee - Based on Face Value, rate 0.0118% (VAT inclusive)
    $fees['csd'] = $face_value * (0.0118 / 100);
    
    // DSE Fee - Based on Face Value, rate 0.02006% (VAT inclusive)
    $fees['dse'] = $face_value * (0.02006 / 100);
    
    $fees['fidelity'] = 0.00;
    
    // Bank Charges (flat fee based on consideration, SELL only)
    if (strtoupper($trade_side) !== 'BUY') {
        if ($consideration < 100000) $fees['bank_charges'] = 250;
        elseif ($consideration < 10000000) $fees['bank_charges'] = 2000;
        elseif ($consideration < 50000000) $fees['bank_charges'] = 6000;
        else $fees['bank_charges'] = 12000;
    } else {
        $fees['bank_charges'] = 0;
    }
    
    $fees['total'] = array_sum([
        $fees['brokerage'],
        $fees['vat'],
        $fees['cmsa'],
        $fees['dse'],
        $fees['csd'],
        $fees['bank_charges']
    ]);
    
    return $fees;
}

// Function to calculate equity fees for contract note
function calculateEquityFeesForContract($db, $consideration, $effective_rate = null, $liberty_mode = 'replace_all', $is_liberty = false, $standard_rate = null, $trade_side = 'Sell') {
    global $standard_rate_percentage;
    
    if ($standard_rate === null) {
        $standard_rate = $standard_rate_percentage ?? 1.7;
    }
    
    $fees = [];
    $fees['tier_details'] = [];
    
    if ($is_liberty && $effective_rate !== null && $effective_rate > 0) {
        if ($liberty_mode === 'tier_override') {
            // Tier override: first 10M standard, excess liberty
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
                    'label' => 'Excess ' . number_format(($consideration - 10000000)/1000000, 1) . 'M @ ' . number_format($effective_rate, 4) . '% (Liberty)'
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
        // Standard tiered calculation using defined tiers
        $config1 = getFeeConfiguration($db, 'BROKERAGE_TIER1', 'EQUITY');
        $rate1 = $config1['rate_percentage'] ?? 1.7000;
        $config2 = getFeeConfiguration($db, 'BROKERAGE_TIER2', 'EQUITY');
        $rate2 = $config2['rate_percentage'] ?? 1.5000;
        $config3 = getFeeConfiguration($db, 'BROKERAGE_TIER3', 'EQUITY');
        $rate3 = $config3['rate_percentage'] ?? 0.8000;

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
                'label' => 'Excess ' . number_format(($consideration - 50000000)/1000000, 1) . 'M @ ' . number_format($rate3, 4) . '%'
            ];
        }
    }
    
    // VAT on brokerage (18%)
    $vat_config = getFeeConfiguration($db, 'VAT', 'ALL');
    $vat_rate = $vat_config['rate_percentage'] ?? 18.0000;
    $fees['vat'] = $fees['brokerage'] * ($vat_rate / 100);
    
    // CMSA Fee (0.14% of consideration)
    $fees['cmsa'] = $consideration * (0.1400 / 100);
    
    // DSE Fee (0.1652% of consideration)
    $fees['dse'] = $consideration * (0.1652 / 100);
    
    // Fidelity Fee (0.02% of consideration)
    $fees['fidelity'] = $consideration * (0.0200 / 100);
    
    // CDS Fee (0.0708% of consideration)
    $fees['csd'] = $consideration * (0.0708 / 100);
    
    // Bank Charges (flat fee based on consideration, SELL only)
    if (strtoupper($trade_side) !== 'BUY') {
        if ($consideration < 100000) $fees['bank_charges'] = 250;
        elseif ($consideration < 10000000) $fees['bank_charges'] = 2000;
        elseif ($consideration < 50000000) $fees['bank_charges'] = 6000;
        else $fees['bank_charges'] = 12000;
    } else {
        $fees['bank_charges'] = 0;
    }
    
    $fees['total'] = array_sum([
        $fees['brokerage'],
        $fees['vat'],
        $fees['cmsa'],
        $fees['dse'],
        $fees['fidelity'],
        $fees['csd'],
        $fees['bank_charges']
    ]);
    
    return $fees;
}

function calculateFeesWithEffectiveRate($db, $asset_class, $consideration, $quantity, $price, $effective_rate, $liberty_mode = 'replace_all', $is_liberty = false, $trade_side = 'Sell') {
    $fees = [];
    $fees['tier_details'] = [];
    
    $is_bond = ($asset_class === 'bond' || $asset_class === 'treasury_bond');
    
    if ($is_bond) {
        $face_value = $quantity;
        $bond_fees = calculateBondFeesForContract($face_value, $consideration, $effective_rate, $liberty_mode, $is_liberty, $trade_side);
        
        $fees['brokerage'] = $bond_fees['brokerage'];
        $fees['tier_details'] = $bond_fees['tier_details'];
        $fees['vat'] = $bond_fees['vat'];
        $fees['cmsa'] = $bond_fees['cmsa'];
        $fees['csd'] = $bond_fees['csd'];
        $fees['dse'] = $bond_fees['dse'];
        $fees['fidelity'] = 0.00;
        $fees['bank_charges'] = $bond_fees['bank_charges'] ?? 0;
        $fees['total'] = $bond_fees['total'];
        
    } else {
        $equity_fees = calculateEquityFeesForContract($db, $consideration, $effective_rate, $liberty_mode, $is_liberty, null, $trade_side);
        
        $fees['brokerage'] = $equity_fees['brokerage'];
        $fees['tier_details'] = $equity_fees['tier_details'];
        $fees['vat'] = $equity_fees['vat'];
        $fees['cmsa'] = $equity_fees['cmsa'];
        $fees['dse'] = $equity_fees['dse'];
        $fees['fidelity'] = $equity_fees['fidelity'];
        $fees['csd'] = $equity_fees['csd'];
        $fees['bank_charges'] = $equity_fees['bank_charges'] ?? 0;
        $fees['total'] = $equity_fees['total'];
    }
    
    return $fees;
}

// Define ContractNotePDF class
class ContractNotePDF extends TCPDF {
    use ReportHeaderTrait;
    
    private $watermark_enabled = false;
    private $company_name = '';
    private $total_trades = 0;
    private $current_trade = 1;
    
    public function setWatermarkEnabled($enabled) {
        $this->watermark_enabled = $enabled;
    }
    
    public function setCompanyName($company_name) {
        $this->company_name = $company_name;
    }
    
    public function setTotalTrades($total) {
        $this->total_trades = $total;
    }
    
    public function setCurrentTrade($current) {
        $this->current_trade = $current;
    }
    
    public function Header() {
        $this->renderReportHeader();
        
        $y = $this->GetY();
        
        $this->SetFont('times', 'I', 7);
        $this->SetTextColor(4, 45, 146);
        $this->SetXY(15, $y);
        $this->Cell(180, 3, '(Subject to the Rules and Practice of the Dar es Salaam Stock Exchange)', 0, 1, 'C');
        $y += 4;
        
        if ($this->total_trades > 1) {
            $this->SetFont('times', '', 6);
            $this->SetTextColor(120, 120, 120);
            $this->SetXY(15, $y);
            $this->Cell(10, 3, 'Trade ' . $this->current_trade . ' of ' . $this->total_trades, 0, 0, 'L');
            $this->SetTextColor(0, 0, 0);
            $y += 3;
        }
        
        if ($this->watermark_enabled) {
            $this->SetAlpha(0.05);
            $this->SetFont('times', 'B', 50);
            $this->SetTextColor(200, 200, 200);
            $this->StartTransform();
            $this->Rotate(45, 105, 150);
            $this->Text(105, 150, $this->company_name);
            $this->StopTransform();
            $this->SetAlpha(1);
            $this->SetTextColor(0, 0, 0);
        }
        
        $this->SetY($y + 2);
    }
    
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('helvetica', '', 4);
        $this->SetTextColor(120, 120, 120);
        
        $disclaimer = "{$this->company_name} has prepared this Report solely for informational purposes. " .
                     "{$this->company_name} does not represent warrant or guarantee that the Reports are accurate.";
        
        $this->MultiCell(160, 1.5, $disclaimer, 0, 'C', false, 1, 25, $this->GetY(), true, 0, false, true, 0, 'T', false);
        
        $this->SetY(-6);
        $this->SetFont('helvetica', 'B', 5);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
    
    private function RotatedText($x, $y, $txt, $angle) {
        $this->StartTransform();
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->StopTransform();
    }
    
    public function addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref, $is_summary = false, $summary_data = null) {
        $is_bond = ($trade['asset_class'] === 'bond' || $trade['asset_class'] === 'treasury_bond');
        $is_etf = ($trade['asset_class'] === 'Exchange Traded Funds');
        $trade_side = strtoupper(trim($trade['trade_side']));
        $is_sell = ($trade_side === 'SELL');
        
        $consideration = floatval($trade['consideration']);
        $quantity = floatval($trade['quantity']);
        $price = floatval($trade['price']);
        $face_value = $quantity;
        
        if ($is_sell) {
            $net_amount = $consideration - $fees['total'];
        } else {
            $net_amount = $consideration + $fees['total'];
        }
        
        $this->AddPage();
        $this->Ln(10);
        
        $this->SetFont('helvetica', '', 7);
        $this->Cell(90, 4, 'Trade Date: ' . date('d/m/Y', strtotime($trade['trade_date'])), 0, 0, 'L');
        $this->Cell(90, 4, 'Account: ' . $trade['client_cds_account'], 0, 1, 'R');
        $this->Cell(90, 4, 'Order No: ' . $order_number, 0, 0, 'L');
        $this->Cell(90, 4, 'Exch Ref: ' . $exchange_ref, 0, 1, 'R');
        $this->Cell(90, 4, 'Settlement: ' . date('d/m/Y', strtotime($trade['settlement_date'])), 0, 1, 'L');
        
        $this->Ln(4);
        
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 5, strtoupper($trade['client_name']), 0, 1, 'L');
        $this->SetFont('helvetica', '', 7);
        if (!empty($trade['client_address'])) {
            $this->Cell(0, 3, 'PO Box: ' . $trade['client_address'], 0, 1, 'L');
        }
        if (!empty($trade['client_email'])) {
            $this->Cell(0, 3, 'Email: ' . $trade['client_email'], 0, 1, 'L');
        }
        
        $this->Ln(10);
        
        $this->SetFont('helvetica', 'B', 9);
        $contract_title = $trade_side . ' CONTRACT NOTE No: ' . $contract_number;
        if ($is_summary) {
            $contract_title .= ' (SUMMARY)';
        }
        
        // Add Liberty Mode to title if applicable
        if ($trade['brokerage_fee_type'] == 'liberty' || $trade['brokerage_fee_type'] == 'this_trade') {
            $liberty_mode_display = '';
            if (isset($trade['liberty_mode'])) {
                if ($trade['liberty_mode'] == 'replace_all') {
                    $liberty_mode_display = ' [Liberty - Full Rate]';
                } elseif ($trade['liberty_mode'] == 'excess_only') {
                    $liberty_mode_display = ' [Liberty - Excess Only]';
                } elseif ($trade['liberty_mode'] == 'tier_override') {
                    $liberty_mode_display = ' [Liberty - Tier Override]';
                }
            }
            $contract_title .= $liberty_mode_display;
        }
        
        $this->Cell(0, 5, $contract_title, 0, 1, 'L');
        
        $this->Ln(2);
        
        $this->SetFont('helvetica', '', 8);
        $instruction = "We wish to advise that in accordance with your instructions we have " . $trade_side . 
                       " on your account subject to the Rules, Regulations and Customs of the Dar es Salaam Stock Exchange:-";
        $this->MultiCell(0, 4, $instruction, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);
        
        $this->Ln(3);
        
        $this->SetFont('helvetica', 'B', 8);
        if ($is_bond) {
            $this->Cell(0, 4, 'TREASURY BOND', 0, 1, 'L');
        } elseif ($is_etf) {
            $this->Cell(0, 4, 'EXCHANGE TRADED FUND', 0, 1, 'L');
        } else {
            $this->Cell(0, 4, '[DSE] EQUITY', 0, 1, 'L');
        }
        
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 4, $trade['security_id'], 0, 1, 'L');
        
        $this->Ln(4);
        
        $this->SetFont('helvetica', 'B', 8);
        $this->SetFillColor(245, 245, 245);
        
        $this->Cell(45, 6, 'SECURITY', 1, 0, 'C', 1);
        $this->Cell(45, 6, 'QUANTITY', 1, 0, 'C', 1);
        $this->Cell(45, 6, 'PRICE', 1, 0, 'C', 1);
        $this->Cell(45, 6, 'CONSIDERATION', 1, 1, 'C', 1);
        
        $this->SetFont('helvetica', '', 8);
        $this->Cell(45, 6, $trade['security_id'], 1, 0, 'C');
        
        if ($is_summary && $summary_data) {
            $this->Cell(45, 6, number_format($summary_data['total_quantity'], ($is_bond ? 2 : 0)), 1, 0, 'C');
            $this->Cell(45, 6, number_format($summary_data['average_price'], ($is_bond ? 6 : 2)), 1, 0, 'C');
        } else {
            $this->Cell(45, 6, number_format($quantity, ($is_bond ? 2 : 0)), 1, 0, 'C');
            $this->Cell(45, 6, number_format($price, ($is_bond ? 6 : 2)), 1, 0, 'C');
        }
        
        $this->Cell(45, 6, number_format($consideration, 2), 1, 1, 'C');
        
        $this->Ln(5);
        
        if ($is_bond) {
            $this->SetFont('helvetica', 'B', 7);
            $this->Cell(25, 3, 'Coupon:', 0, 0, 'L');
            $this->SetFont('helvetica', '', 7);
            $this->Cell(25, 3, number_format($trade['coupon_rate'] ?? 15.49, 4) . '%', 0, 0, 'L');
            
            $this->SetFont('helvetica', 'B', 7);
            $this->Cell(35, 3, 'Maturity Date:', 0, 0, 'L');
            $this->SetFont('helvetica', '', 7);
            $maturity_date = !empty($trade['maturity_date']) && $trade['maturity_date'] != '0000-00-00' ? 
                date('d/m/Y', strtotime($trade['maturity_date'])) : date('d/m/Y', strtotime('+5 years', strtotime($trade['trade_date'])));
            $this->Cell(0, 3, $maturity_date, 0, 1, 'L');
            
            $this->SetFont('helvetica', 'B', 7);
            $this->Cell(25, 3, 'Face Value:', 0, 0, 'L');
            $this->SetFont('helvetica', '', 7);
            $this->Cell(0, 3, 'TZS ' . number_format($face_value, 2), 0, 1, 'L');
            
            $this->Ln(2);
        }
        
        $this->Ln(2);
        
        $this->SetFont('helvetica', 'B', 8);
        $this->Cell(0, 5, 'FEES AND CHARGES', 0, 1, 'L');
        $this->SetFont('helvetica', '', 7);
        
        $this->Cell(0, 3, 'Brokerage Commission:', 0, 1, 'L');
        
        $commission_type_display = '';
        $effective_rate_for_display = isset($trade['effective_rate_for_display']) ? $trade['effective_rate_for_display'] : null;
        
        if ($trade['brokerage_fee_type'] == 'normal') {
            $commission_type_display = '(Standard Rate)';
        } elseif ($trade['brokerage_fee_type'] == 'liberty') {
            $commission_type_display = '(Liberty - Permanent Client Rate)';
            if (isset($trade['liberty_mode'])) {
                if ($trade['liberty_mode'] == 'excess_only') {
                    $commission_type_display .= ' - Excess Only Mode';
                } elseif ($trade['liberty_mode'] == 'replace_all') {
                    $commission_type_display .= ' - Full Rate Mode';
                }
            }
            if ($effective_rate_for_display && $effective_rate_for_display > 0) {
                $commission_type_display .= ' - ' . number_format($effective_rate_for_display, 4) . '%';
            }
        } elseif ($trade['brokerage_fee_type'] == 'this_trade') {
            $commission_type_display = '(One-Time Custom Rate)';
            if (isset($trade['liberty_mode'])) {
                if ($trade['liberty_mode'] == 'excess_only') {
                    $commission_type_display .= ' - Excess Only Mode';
                } elseif ($trade['liberty_mode'] == 'tier_override') {
                    $commission_type_display .= ' - Tier Override Mode';
                } elseif ($trade['liberty_mode'] == 'replace_all') {
                    $commission_type_display .= ' - Full Rate Mode';
                }
            }
            if ($effective_rate_for_display && $effective_rate_for_display > 0) {
                $commission_type_display .= ' - ' . number_format($effective_rate_for_display, 4) . '%';
            }
        }
        
        $this->SetFont('helvetica', 'I', 6);
        $this->Cell(0, 3, $commission_type_display, 0, 1, 'L');
        $this->SetFont('helvetica', '', 7);
        
        if (!empty($fees['tier_details'])) {
            foreach ($fees['tier_details'] as $tier) {
                $this->Cell(15, 2.5, '', 0, 0, 'L');
                $this->Cell(70, 2.5, $tier['label'], 0, 0, 'L');
                $this->Cell(40, 2.5, number_format($tier['fee'], 2), 0, 1, 'R');
            }
        }
        
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(85, 3, '', 0, 0, 'L');
        $this->Cell(50, 3, 'Total Brokerage:', 0, 0, 'L');
        $this->Cell(40, 3, number_format($fees['brokerage'], 2), 0, 1, 'R');
        
        $this->SetFont('helvetica', '', 7);
        $this->Cell(100, 3.5, 'VAT on Brokerage Commission', 0, 0, 'L');
        $this->Cell(40, 3.5, '@ 18.000%', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($fees['vat'], 2), 0, 1, 'R');
        
        $this->Cell(100, 3.5, 'CMSA Transaction Fee', 0, 0, 'L');
        if ($is_bond) {
            $this->Cell(40, 3.5, '@ 0.0100%', 0, 0, 'L');
        } else {
            $this->Cell(40, 3.5, '@ 0.1400%', 0, 0, 'L');
        }
        $this->Cell(40, 3.5, number_format($fees['cmsa'], 2), 0, 1, 'R');
        
        $this->Cell(100, 3.5, 'DSE Transaction Fee(VAT INCL)', 0, 0, 'L');
        if ($is_bond) {
            $this->Cell(40, 3.5, '@ 0.02006%', 0, 0, 'L');
        } else {
            $this->Cell(40, 3.5, '@ 0.1652%', 0, 0, 'L');
        }
        $this->Cell(40, 3.5, number_format($fees['dse'], 2), 0, 1, 'R');
        
        if (!$is_bond) {
            $this->Cell(100, 3.5, 'Fidelity Fee', 0, 0, 'L');
            $this->Cell(40, 3.5, '@ 0.0200%', 0, 0, 'L');
            $this->Cell(40, 3.5, number_format($fees['fidelity'], 2), 0, 1, 'R');
        }
        
        $this->Cell(100, 3.5, 'CDS Fee(VAT INCL)', 0, 0, 'L');
        if ($is_bond) {
            $this->Cell(40, 3.5, '@ 0.0118%', 0, 0, 'L');
        } else {
            $this->Cell(40, 3.5, '@ 0.0708%', 0, 0, 'L');
        }
        $this->Cell(40, 3.5, number_format($fees['csd'], 2), 0, 1, 'R');
        
        $bank_charge = isset($fees['bank_charges']) ? floatval($fees['bank_charges']) : (isset($fees['bank_charge']) ? floatval($fees['bank_charge']) : 0);
        $this->Cell(100, 3.5, 'Bank Charges', 0, 0, 'L');
        $this->Cell(40, 3.5, '', 0, 0, 'L');
        $this->Cell(40, 3.5, number_format($bank_charge, 2), 0, 1, 'R');
        
        $this->SetLineWidth(0.2);
        $this->Line(25, $this->GetY() + 1, 185, $this->GetY() + 1);
        $this->Ln(2);
        
        $this->SetFont('helvetica', 'B', 8);
        $this->Cell(100, 4, 'Total Charges', 0, 0, 'L');
        $this->Cell(40, 4, '', 0, 0, 'L');
        $this->Cell(40, 4, number_format($fees['total'], 2), 0, 1, 'R');
        
        $this->Ln(2);
        
        $this->SetLineWidth(0.5);
        $this->Line(25, $this->GetY() + 1, 185, $this->GetY() + 1);
        $this->Ln(3);
        
        $amount_label = $is_sell ? 'NET AMOUNT RECEIVABLE' : 'NET AMOUNT PAYABLE';
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(100, 6, $amount_label, 0, 0, 'L');
        $this->Cell(40, 6, '', 0, 0, 'L');
        $this->Cell(40, 6, number_format($net_amount, 2), 0, 1, 'R');
        
        $this->Ln(50);
        
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 4, 'Yours Faithfully,', 0, 1, 'L');
        $this->Ln(3);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 5, 'FOR ' . $this->company_name, 0, 1, 'L');
        
        $this->Ln(6);
        
        $this->SetFont('helvetica', '', 7);
        $this->Cell(80, 8, '', 0, 0, 'L');
        $this->Cell(80, 8, '', 0, 1, 'L');
        
        $this->SetLineWidth(0.3);
        $this->Line(25, $this->GetY() - 6, 105, $this->GetY() - 6);
        $this->Line(110, $this->GetY() - 6, 185, $this->GetY() - 6);
        
        $this->SetFont('helvetica', 'B', 7);
        $this->Cell(80, 3, 'SIGNATURE OF CLIENT', 0, 0, 'C');
        $this->Cell(80, 3, 'STAMP & SIGNATURE OF LDM', 0, 1, 'C');
    }
    
    public function addSummaryBreakdown($trades, $summary_data, $client_name, $trade_date, $security_id, $trade_side) {
        $this->AddPage();
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 10, 'TRADE BREAKDOWN - ' . strtoupper($trade_side) . ' SUMMARY', 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 8, 'Client: ' . strtoupper($client_name), 0, 1, 'L');
        $this->Cell(0, 8, 'Trade Date: ' . date('d/m/Y', strtotime($trade_date)), 0, 1, 'L');
        $this->Cell(0, 8, 'Security: ' . $security_id, 0, 1, 'L');
        $this->Ln(5);
        
        $this->SetFont('helvetica', 'B', 8);
        $this->SetFillColor(245, 245, 245);
        
        $this->Cell(15, 8, '#', 1, 0, 'C', 1);
        $this->Cell(20, 8, 'Ref No', 1, 0, 'C', 1);
        $this->Cell(20, 8, 'Quantity', 1, 0, 'C', 1);
        $this->Cell(25, 8, 'Price', 1, 0, 'C', 1);
        $this->Cell(30, 8, 'Consideration', 1, 0, 'C', 1);
        $this->Cell(25, 8, 'Commission', 1, 0, 'C', 1);
        $this->Cell(25, 8, 'Liberty Mode', 1, 0, 'C', 1);
        $this->Cell(35, 8, 'Time', 1, 1, 'C', 1);
        
        $this->SetFont('helvetica', '', 7);
        $counter = 1;
        foreach ($trades as $trade) {
            $liberty_mode_display = '';
            if (isset($trade['liberty_mode'])) {
                if ($trade['liberty_mode'] == 'replace_all') {
                    $liberty_mode_display = 'Full Rate';
                } elseif ($trade['liberty_mode'] == 'excess_only') {
                    $liberty_mode_display = 'Excess Only';
                } elseif ($trade['liberty_mode'] == 'tier_override') {
                    $liberty_mode_display = 'Tier Override';
                }
            } else {
                $liberty_mode_display = 'Standard';
            }
            
            $this->Cell(15, 6, $counter++, 1, 0, 'C');
            $this->Cell(20, 6, substr($trade['trade_reference'], -6), 1, 0, 'C');
            $this->Cell(20, 6, number_format($trade['quantity'], 0), 1, 0, 'R');
            $this->Cell(25, 6, number_format($trade['price'], 4), 1, 0, 'R');
            $this->Cell(30, 6, number_format($trade['consideration'], 2), 1, 0, 'R');
            $this->Cell(25, 6, number_format($trade['final_brokerage_fee'], 2), 1, 0, 'R');
            $this->Cell(25, 6, $liberty_mode_display, 1, 0, 'C');
            $this->Cell(35, 6, date('H:i', strtotime($trade['created_at'])), 1, 1, 'C');
        }
        
        $this->Ln(5);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(95, 8, 'TOTAL QUANTITY:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['total_quantity'], 0), 0, 1, 'R');
        
        $this->Cell(95, 8, 'AVERAGE PRICE:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['average_price'], 4), 0, 1, 'R');
        
        $this->Cell(95, 8, 'TOTAL CONSIDERATION:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['total_consideration'], 2), 0, 1, 'R');
        
        $this->Cell(95, 8, 'TOTAL COMMISSION:', 0, 0, 'R');
        $this->Cell(40, 8, number_format($summary_data['total_fees'], 2), 0, 1, 'R');
        
        $this->Cell(95, 8, 'NUMBER OF TRADES:', 0, 0, 'R');
        $this->Cell(40, 8, $summary_data['trade_count'], 0, 1, 'R');
    }
}

// Function to get fee configuration from database
function getFeeConfiguration($db, $fee_type, $applies_to = 'ALL') {
    $stmt = $db->prepare("
        SELECT rate_percentage, fixed_amount, calculation_base, fee_name, is_rebated, rebate_percentage, rebate_tiers
        FROM fee_configurations 
        WHERE fee_type = ? AND applies_to = ? AND is_active = TRUE
        ORDER BY applies_to DESC
        LIMIT 1
    ");
    $stmt->execute([$fee_type, $applies_to]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config && $applies_to !== 'ALL') {
        $stmt->execute([$fee_type, 'ALL']);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $config;
}

// Handle trade actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $trade_id = (int)$_GET['id'];
    
    $stmt = $db->prepare("
        SELECT t.*, cl.fee_type as client_fee_type, cl.default_brokerage_fee, cl.liberty_mode as client_liberty_mode
        FROM trades t
        LEFT JOIN clients cl ON t.client_name = cl.client_name 
        WHERE t.id = ?
    ");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch();
    
    if ($trade) {
        switch ($action) {
            case 'cancel':
                $stmt = $db->prepare("UPDATE trades SET status = 'cancelled' WHERE id = ?");
                if ($stmt->execute([$trade_id])) {
                    syncTradeToDealingSheetSafely($db, $trade_id, $current_user);
                    show_alert('Trade cancelled successfully.', 'warning');
                } else {
                    show_alert('Error cancelling trade.', 'danger');
                }
                break;
                
            case 'enable':
                $stmt = $db->prepare("UPDATE trades SET status = 'active' WHERE id = ?");
                if ($stmt->execute([$trade_id])) {
                    syncTradeToDealingSheetSafely($db, $trade_id, $current_user);
                    show_alert('Trade enabled successfully.', 'success');
                } else {
                    show_alert('Error enabling trade.', 'danger');
                }
                break;
                
            case 'settle':
                $stmt = $db->prepare("UPDATE trades SET status = 'settled' WHERE id = ?");
                if ($stmt->execute([$trade_id])) {
                    syncTradeToDealingSheetSafely($db, $trade_id, $current_user);
                    show_alert('Trade marked as settled successfully.', 'success');
                } else {
                    show_alert('Error settling trade.', 'danger');
                }
                break;
                
            case 'contract_note':
                $client_id = $trade['client_cds_account'];
                $trade_date = $trade['trade_date'];
                $trade_side = $trade['trade_side'];
                $security_id = $trade['security_id'];
                
                $stmt = $db->prepare("
                    SELECT COUNT(*) as trade_count 
                    FROM trades 
                    WHERE client_cds_account = ? 
                    AND trade_date = ? 
                    AND trade_side = ? 
                    AND security_id = ?
                    AND status = 'active'
                ");
                $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]);
                $result = $stmt->fetch();
                
                if ($result['trade_count'] > 1) {
                    $_SESSION['contract_modal_data'] = [
                        'client_id' => $client_id,
                        'trade_date' => $trade_date,
                        'trade_side' => $trade_side,
                        'security_id' => $security_id,
                        'trigger_trade_id' => $trade_id
                    ];
                    
                    header('Location: trades?show_contract_modal=1');
                    exit;
                } else {
                    generateContractNotePDF($trade_id, 'single');
                }
                exit;
        }
    } else {
        show_alert('Trade not found or access denied.', 'danger');
    }
    
    redirect('trader/trades.php');
}

// Function to generate Contract Note PDF - FIXED for liberty rate
function generateContractNotePDF($trade_id, $contract_type = 'single') {
    global $db, $company_name, $current_user, $standard_rate_percentage;
    
    $stmt = $db->prepare("
        SELECT t.*, 
               e.share_type,
               et.isin as etf_isin,
               b.coupon_rate,
               b.maturity_date,
               b.isin as bond_isin,
               c_buyer.company_name as buyer_company_name,
               c_buyer.company_code as buyer_cds_account,
               c_seller.company_name as seller_company_name,
               c_seller.company_code as seller_cds_account,
               cl.fee_type as client_fee_type,
               cl.default_brokerage_fee,
               cl.liberty_mode as client_liberty_mode,
               cl.address as client_address,
               cl.email as client_email
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
        LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
        LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
        LEFT JOIN clients cl ON t.client_name = cl.client_name
        WHERE t.id = ?
    ");
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch();
    
    if (!$trade) {
        die('Trade not found');
    }

    markTradeContractNoteGeneratedSafely($db, $trade_id, $current_user);
    
    $client = [
        'fee_type' => $trade['client_fee_type'],
        'default_brokerage_fee' => $trade['default_brokerage_fee'],
        'liberty_mode' => $trade['client_liberty_mode']
    ];
    
    // Get effective rate with proper liberty handling
    $effective_rate = getEffectiveBrokerageRate($db, $trade, $client);
    
    // Debug log
    error_log("Contract Note - Trade ID: {$trade_id}, Rate: {$effective_rate}, Fee Type: {$trade['brokerage_fee_type']}");
    
    // Determine liberty mode (from trade, client, or default)
    $liberty_mode = 'replace_all';
    $is_liberty = false;
    
    if ($trade['brokerage_fee_type'] === 'liberty' || $trade['brokerage_fee_type'] === 'this_trade') {
        $is_liberty = true;
        $liberty_mode = $trade['liberty_mode'] ?? ($client['liberty_mode'] ?? 'replace_all');
    }
    
    $fees = calculateFeesWithEffectiveRate($db, $trade['asset_class'], 
                         floatval($trade['consideration']), 
                         floatval($trade['quantity']), 
                         floatval($trade['price']),
                         $effective_rate,
                         $liberty_mode,
                         $is_liberty,
                         $trade['trade_side'] ?? 'Sell');
    
    $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Contract Note - ' . $trade['trade_reference']);
    $pdf->SetSubject('Trade Contract Note');

    $pdf->setWatermarkEnabled(true);
    $pdf->setCompanyName($company_name);
    $pdf->setTotalTrades(1);

    $pdf->SetHeaderData('', 0, '', '');
    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);
    $pdf->SetAutoPageBreak(TRUE, 12);

    $trade_side = strtoupper(trim($trade['trade_side']));
    $contract_number = ($trade_side === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
    
    // Pass liberty mode and effective rate to contract note
    $trade['liberty_mode'] = $liberty_mode;
    $trade['effective_rate_for_display'] = $effective_rate;
    
    $pdf->setCurrentTrade(1);
    $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);

    $filename = 'contract_note_' . $trade['trade_reference'] . '.pdf';
    $pdf->Output($filename, 'I');
}

// Function to generate summary contract note for multiple trades
function generateSummaryContractNote($client_id, $trade_date, $trade_side, $security_id) {
    global $db, $company_name, $current_user, $standard_rate_percentage;
    
    $stmt = $db->prepare("
        SELECT t.*, 
               e.share_type,
               b.coupon_rate,
               b.maturity_date,
               c_buyer.company_name as buyer_company_name,
               c_seller.company_name as seller_company_name,
               cl.fee_type as client_fee_type,
               cl.default_brokerage_fee,
               cl.liberty_mode as client_liberty_mode
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
        LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
        LEFT JOIN clients cl ON t.client_name = cl.client_name 
        WHERE t.client_cds_account = ? 
        AND t.trade_date = ? 
        AND t.trade_side = ? 
        AND t.security_id = ?
        AND t.status = 'active'
        ORDER BY t.created_at
    ");
    $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]);
    $trades = $stmt->fetchAll();
    
    if (empty($trades)) {
        die('No trades found for summary');
    }

    foreach ($trades as $trade) {
        markTradeContractNoteGeneratedSafely($db, $trade['id'], $current_user);
    }
    
    $total_quantity = 0;
    $total_consideration = 0;
    $total_fees = 0;
    $trade_count = count($trades);
    $client_name = $trades[0]['client_name'];
    $asset_class = $trades[0]['asset_class'];
    
    $first_trade = $trades[0];
    $client = [
        'fee_type' => $first_trade['client_fee_type'],
        'default_brokerage_fee' => $first_trade['default_brokerage_fee'],
        'liberty_mode' => $first_trade['client_liberty_mode']
    ];
    $effective_rate = getEffectiveBrokerageRate($db, $first_trade, $client);
    
    // Determine liberty mode for summary
    $liberty_mode = $first_trade['liberty_mode'] ?? ($client['liberty_mode'] ?? 'replace_all');
    $is_liberty = ($first_trade['brokerage_fee_type'] === 'liberty' || $first_trade['brokerage_fee_type'] === 'this_trade');
    
    foreach ($trades as $trade) {
        $total_quantity += floatval($trade['quantity']);
        $total_consideration += floatval($trade['consideration']);
        
        $fees = calculateFeesWithEffectiveRate($db, $trade['asset_class'], 
                             floatval($trade['consideration']), 
                             floatval($trade['quantity']), 
                             floatval($trade['price']),
                             $effective_rate,
                             $liberty_mode,
                             $is_liberty,
                             $trade['trade_side'] ?? 'Sell');
        $total_fees += $fees['total'];
    }
    
    $average_price = $total_consideration / $total_quantity;
    
    $summary_trade = $trades[0];
    $summary_trade['quantity'] = $total_quantity;
    $summary_trade['price'] = $average_price;
    $summary_trade['consideration'] = $total_consideration;
    $summary_trade['brokerage_fee_type'] = $first_trade['brokerage_fee_type'];
    $summary_trade['custom_brokerage_fee'] = $first_trade['custom_brokerage_fee'];
    $summary_trade['liberty_mode'] = $liberty_mode;
    $summary_trade['effective_rate_for_display'] = $effective_rate;
    
    $summary_fees = calculateFeesWithEffectiveRate($db, $asset_class, $total_consideration, $total_quantity, $average_price, $effective_rate, $liberty_mode, $is_liberty, $first_trade['trade_side'] ?? 'Sell');
    
    $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Contract Note Summary - ' . $client_id);
    $pdf->SetSubject('Trade Contract Note Summary');

    $pdf->setWatermarkEnabled(true);
    $pdf->setCompanyName($company_name);
    $pdf->setTotalTrades(1);

    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);
    $pdf->SetAutoPageBreak(TRUE, 12);

    $trade_side_upper = strtoupper(trim($trade_side));
    $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trades[0]['id'], 6, '0', STR_PAD_LEFT) . '-SUM';
    $order_number = 'SUMMARY-' . date('ymd', strtotime($trade_date));
    $exchange_ref = date('ymd', strtotime($trade_date)) . 'SUM';
    
    $summary_data = [
        'total_quantity' => $total_quantity,
        'average_price' => $average_price,
        'trade_count' => $trade_count,
        'total_consideration' => $total_consideration,
        'total_fees' => $total_fees
    ];
    
    $pdf->setCurrentTrade(1);
    $pdf->addContractNote($summary_trade, $summary_fees, $contract_number, $order_number, $exchange_ref, true, $summary_data);
    $pdf->addSummaryBreakdown($trades, $summary_data, $client_name, $trade_date, $security_id, $trade_side);

    $filename = 'contract_note_summary_' . $client_id . '_' . date('Ymd', strtotime($trade_date)) . '.pdf';
    $pdf->Output($filename, 'I');
}

// Function to generate detailed contract notes for multiple trades
function generateDetailedContractNotes($client_id, $trade_date, $trade_side, $security_id) {
    global $db, $company_name, $current_user, $standard_rate_percentage;
    
    $stmt = $db->prepare("
        SELECT t.*, 
               e.share_type,
               b.coupon_rate,
               b.maturity_date,
               c_buyer.company_name as buyer_company_name,
               c_seller.company_name as seller_company_name,
               cl.fee_type as client_fee_type,
               cl.default_brokerage_fee,
               cl.liberty_mode as client_liberty_mode
        FROM trades t
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
        LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
        LEFT JOIN clients cl ON t.client_name = cl.client_name 
        WHERE t.client_cds_account = ? 
        AND t.trade_date = ? 
        AND t.trade_side = ? 
        AND t.security_id = ?
        AND t.status = 'active'
        ORDER BY t.created_at
    ");
    $stmt->execute([$client_id, $trade_date, $trade_side, $security_id]);
    $trades = $stmt->fetchAll();
    
    if (empty($trades)) {
        die('No trades found for detailed notes');
    }

    foreach ($trades as $trade) {
        markTradeContractNoteGeneratedSafely($db, $trade['id'], $current_user);
    }
    
    $pdf = new ContractNotePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Contract Notes - ' . $client_id);
    $pdf->SetSubject('Detailed Trade Contract Notes');

    $pdf->setWatermarkEnabled(true);
    $pdf->setCompanyName($company_name);
    $pdf->setTotalTrades(count($trades));

    $pdf->SetMargins(25.4, 25, 25.4);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);
    $pdf->SetAutoPageBreak(TRUE, 12);

    $trade_index = 1;
    foreach ($trades as $trade) {
        $client = [
            'fee_type' => $trade['client_fee_type'],
            'default_brokerage_fee' => $trade['default_brokerage_fee'],
            'liberty_mode' => $trade['client_liberty_mode']
        ];
        $effective_rate = getEffectiveBrokerageRate($db, $trade, $client);
        
        $liberty_mode = $trade['liberty_mode'] ?? ($client['liberty_mode'] ?? 'replace_all');
        $is_liberty = ($trade['brokerage_fee_type'] === 'liberty' || $trade['brokerage_fee_type'] === 'this_trade');
        
        $fees = calculateFeesWithEffectiveRate($db, $trade['asset_class'], 
                             floatval($trade['consideration']), 
                             floatval($trade['quantity']), 
                             floatval($trade['price']),
                             $effective_rate,
                             $liberty_mode,
                             $is_liberty,
                             $trade['trade_side'] ?? 'Sell');
        
        $trade_side_upper = strtoupper(trim($trade['trade_side']));
        $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
        $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
        $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
        
        // Pass liberty mode and effective rate to contract note
        $trade['liberty_mode'] = $liberty_mode;
        $trade['effective_rate_for_display'] = $effective_rate;
        
        $pdf->setCurrentTrade($trade_index);
        $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);
        
        $trade_index++;
    }

    $filename = 'contract_notes_detailed_' . $client_id . '_' . date('Ymd', strtotime($trade_date)) . '.pdf';
    $pdf->Output($filename, 'I');
}

// Handle contract note generation based on modal selection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_contract_note'])) {
    $contract_type = $_POST['contract_type'];
    $client_id = $_POST['client_id'];
    $trade_date = $_POST['trade_date'];
    $trade_side = $_POST['trade_side'];
    $security_id = $_POST['security_id'];
    $trigger_trade_id = $_POST['trigger_trade_id'];
    
    if ($contract_type == 'single') {
        generateContractNotePDF($trigger_trade_id, 'single');
    } elseif ($contract_type == 'summary') {
        generateSummaryContractNote($client_id, $trade_date, $trade_side, $security_id);
    } elseif ($contract_type == 'detailed') {
        generateDetailedContractNotes($client_id, $trade_date, $trade_side, $security_id);
    }
    exit;
}

// Handle trade upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_trade'])) {
    $asset_class = sanitize_input($_POST['asset_class']);
    $security_id = sanitize_input($_POST['security_id']);
    $trade_side = sanitize_input($_POST['trade_side']);
    $trade_reference = sanitize_input($_POST['trade_reference']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $client_name = sanitize_input($_POST['client_name']);
    $counterparty_name = sanitize_input($_POST['counterparty_name']);
    $client_cds_account = sanitize_input($_POST['client_cds_account']);
    $counterparty_cds_account = sanitize_input($_POST['counterparty_cds_account']);
    $trade_date = sanitize_input($_POST['trade_date']);
    $settlement_date = sanitize_input($_POST['settlement_date']);

    $consideration = $quantity * $price;
    
    if (empty($asset_class) || empty($security_id) || empty($trade_side) || 
        empty($quantity) || empty($price) || empty($client_name) || empty($counterparty_name) ||
        empty($trade_date) || empty($settlement_date)) {
        $error_message = 'All fields are required.';
    } elseif ($quantity <= 0 || $price <= 0) {
        $error_message = 'Quantity and price must be greater than zero.';
    } else {
        $check_ref_stmt = $db->prepare("SELECT COUNT(*) FROM trades WHERE trade_reference = ?");
        $check_ref_stmt->execute([$trade_reference]);
        $ref_exists = $check_ref_stmt->fetchColumn() > 0;
        
        if ($ref_exists) {
            $error_message = 'Trade reference "' . htmlspecialchars($trade_reference) . '" already exists.';
        } else {
            $stmt_client = $db->prepare("SELECT fee_type, default_brokerage_fee, liberty_mode FROM clients WHERE client_name = ?");
            $stmt_client->execute([$client_name]);
            $client = $stmt_client->fetch();
            
            $brokerage_fee_type = 'normal';
            $custom_brokerage_fee = null;
            $final_brokerage_fee = 0;
            $liberty_mode = 'replace_all';
            
            if ($client && $client['fee_type'] == 'liberty' && $client['default_brokerage_fee'] !== null) {
                $brokerage_fee_type = 'liberty';
                $liberty_mode = $client['liberty_mode'] ?? 'replace_all';
                $temp_trade = [
                    'asset_class' => $asset_class,
                    'quantity' => $quantity,
                    'consideration' => $consideration
                ];
                $final_brokerage_fee = calculateBrokerageFee($temp_trade, $client['default_brokerage_fee'], $liberty_mode);
            } else {
                $asset_class_upper = strtoupper($asset_class);
                if ($asset_class_upper === 'EXCHANGE TRADED FUNDS') {
                    $asset_class_upper = 'ETF';
                }
                
                $stmt_fee = $db->prepare("
                    SELECT rate_percentage FROM fee_configurations
                    WHERE fee_type = 'BROKERAGE' AND applies_to = ? AND is_active = 1
                ");
                $stmt_fee->execute([$asset_class_upper]);
                $standard_rate = $stmt_fee->fetchColumn();
                
                if (!$standard_rate) {
                    $stmt_fee = $db->prepare("
                        SELECT rate_percentage FROM fee_configurations
                        WHERE fee_type = 'BROKERAGE' AND applies_to = 'ALL' AND is_active = 1
                    ");
                    $stmt_fee->execute();
                    $standard_rate = $stmt_fee->fetchColumn();
                }
                
                $is_bond_type = in_array(strtolower($asset_class), ['bond', 'treasury_bond', 'treasury_bill']);
                if ($is_bond_type) {
                    $temp_trade = [
                        'asset_class' => $asset_class,
                        'quantity' => $quantity,
                        'consideration' => $consideration
                    ];
                    $final_brokerage_fee = calculateBrokerageFee($temp_trade, $standard_rate, 'replace_all');
                } else {
                    // Equity tiered brokerage
                    $config1 = getFeeConfiguration($db, 'BROKERAGE_TIER1', 'EQUITY');
                    $rate1 = $config1['rate_percentage'] ?? 1.7000;
                    $config2 = getFeeConfiguration($db, 'BROKERAGE_TIER2', 'EQUITY');
                    $rate2 = $config2['rate_percentage'] ?? 1.5000;
                    $config3 = getFeeConfiguration($db, 'BROKERAGE_TIER3', 'EQUITY');
                    $rate3 = $config3['rate_percentage'] ?? 0.8000;

                    if ($consideration <= 10000000) {
                        $final_brokerage_fee = $consideration * ($rate1 / 100);
                    } elseif ($consideration <= 50000000) {
                        $final_brokerage_fee = 10000000 * ($rate1 / 100) + ($consideration - 10000000) * ($rate2 / 100);
                    } else {
                        $final_brokerage_fee = 10000000 * ($rate1 / 100) + 40000000 * ($rate2 / 100) + ($consideration - 50000000) * ($rate3 / 100);
                    }
                }
            }
            
            $sca_code = 'S' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        
            $stmt = $db->prepare("
                INSERT INTO trades (trade_reference, asset_class, security_id, security_name, trade_side, quantity, price, 
                                    consideration, client_name, counterparty_name, client_cds_account, counterparty_cds_account, 
                                    trade_date, settlement_date, uploaded_by, sca_code, settled_at,
                                    brokerage_fee_type, custom_brokerage_fee, liberty_mode, final_brokerage_fee) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$trade_reference, $asset_class, $security_id, $security_id, $trade_side, $quantity, $price,
                                $consideration, $client_name, $counterparty_name, $client_cds_account, $counterparty_cds_account,
                                $trade_date, $settlement_date, $_SESSION['user_id'], $sca_code,
                                $brokerage_fee_type, $custom_brokerage_fee, $liberty_mode, $final_brokerage_fee])) {
                show_alert('Trade uploaded successfully with reference: ' . $trade_reference, 'success');
                redirect('trader/trades.php');
            } else {
                $error_message = 'Error uploading trade. Please try again.';
            }
        }
    }
}

// Get available instruments
$bonds = [];
$equities = [];
$etfs = [];

$stmt = $db->query("SELECT id, security_id, issued_amount FROM bonds WHERE status = 'active' ORDER BY security_id");
$bonds = $stmt->fetchAll();

$stmt = $db->query("SELECT id, security_id, stock_name FROM equities WHERE status = 'active' ORDER BY security_id");
$equities = $stmt->fetchAll();

$stmt = $db->query("SELECT id, security_id, stock_name FROM equities WHERE status = 'active' AND share_type = 'ETF' ORDER BY security_id");
$etfs = $stmt->fetchAll();

$companies = [];
$stmt = $db->query("SELECT id, company_name, company_code FROM companies WHERE status = 'active' ORDER BY company_name");
$companies = $stmt->fetchAll();

$clients = [];
$stmt = $db->query("SELECT id, client_name, cds_account, fee_type, default_brokerage_fee, liberty_mode FROM clients WHERE status = 'active' ORDER BY client_name");
$clients = $stmt->fetchAll();

// Get trades with filtering
$where_conditions = [];
$params = [];

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where_conditions[] = "t.status = ?";
    $params[] = $_GET['status'];
}

if (isset($_GET['type']) && !empty($_GET['type'])) {
    $where_conditions[] = "t.asset_class = ?";
    $params[] = $_GET['type'];
}

if (isset($_GET['trade_date_from']) && !empty($_GET['trade_date_from'])) {
    $where_conditions[] = "t.trade_date >= ?";
    $params[] = $_GET['trade_date_from'];
}

if (isset($_GET['trade_date_to']) && !empty($_GET['trade_date_to'])) {
    $where_conditions[] = "t.trade_date <= ?";
    $params[] = $_GET['trade_date_to'];
}

if (isset($_GET['settlement_date_from']) && !empty($_GET['settlement_date_from'])) {
    $where_conditions[] = "t.settlement_date >= ?";
    $params[] = $_GET['settlement_date_from'];
}

if (isset($_GET['settlement_date_to']) && !empty($_GET['settlement_date_to'])) {
    $where_conditions[] = "t.settlement_date <= ?";
    $params[] = $_GET['settlement_date_to'];
}

if (isset($_GET['commission_type']) && !empty($_GET['commission_type'])) {
    $where_conditions[] = "t.brokerage_fee_type = ?";
    $params[] = $_GET['commission_type'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(t.trade_reference LIKE ? OR t.security_id LIKE ? OR t.security_name LIKE ? OR t.client_name LIKE ? OR t.client_cds_account LIKE ? OR t.counterparty_name LIKE ? OR t.asset_class LIKE ? OR t.trade_side LIKE ? OR t.status LIKE ? OR t.brokerage_fee_type LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (empty($where_conditions)) {
    $where_clause = "1=1";
} else {
    $where_clause = implode(' AND ', $where_conditions);
}

$allowed_page_sizes = [10, 25, 50, 100, 500];
$default_per_page = 50;
$per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : $default_per_page;
if (!in_array($per_page, $allowed_page_sizes, true)) {
    $per_page = $default_per_page;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$count_stmt = $db->prepare("SELECT COUNT(*) AS total FROM trades t WHERE $where_clause");
$count_stmt->execute($params);
$total_trades = (int) $count_stmt->fetch()['total'];

$total_pages = max(1, (int) ceil($total_trades / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("
    SELECT t.*, 
           COALESCE(e.stock_name, b.security_id, etf.stock_name) AS asset_name,
           e.share_type,
           et.isin as etf_isin,
           c_buyer.company_name as buyer_company_name,
           c_seller.company_name as seller_company_name,
           cl.id as client_id,
           cl.client_name as proper_client_name,
                cl.fee_type as client_fee_type,
                cl.default_brokerage_fee,
                cl.liberty_mode as client_liberty_mode,
                cl.address as client_address,
                cl.email as client_email
    FROM trades t
    LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
    LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
    LEFT JOIN equities etf ON t.security_id = etf.security_id AND t.asset_class = 'Exchange Traded Funds'
    LEFT JOIN etf_trades et ON t.trade_reference = et.trade_reference
    LEFT JOIN companies c_buyer ON t.client_name = c_buyer.company_name
    LEFT JOIN companies c_seller ON t.counterparty_name = c_seller.company_name
    LEFT JOIN clients cl ON t.client_cds_account = cl.cds_account AND cl.is_active = 1
    WHERE $where_clause
    ORDER BY t.created_at DESC
    LIMIT " . (int) $per_page . " OFFSET " . (int) $offset . "
");

$stmt->execute($params);
$trades = $stmt->fetchAll();

$current_page_count = count($trades);
$showing_from = $total_trades > 0 ? $offset + 1 : 0;
$showing_to = $total_trades > 0 ? $offset + $current_page_count : 0;

$show_contract_modal = isset($_GET['show_contract_modal']) && isset($_SESSION['contract_modal_data']);

$page_title = 'Trade Management';

// AJAX handler for live search - returns JSON with table fragment
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($is_ajax) {
    ob_start();
}

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
                            <i class="bi bi-graph-up" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Trade Management</h1>
                        <p class="page-subtitle">Professional trading operations and portfolio oversight - <?php echo htmlspecialchars($company_name); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <a href="enter_bonds.php" class="btn btn-outline-success d-flex align-items-center">
                        <i class="bi bi-bank me-2"></i>
                        <span class="d-none d-sm-inline">Enter Bonds</span>
                    </a>
                    <a href="enter_shares.php" class="btn btn-outline-info d-flex align-items-center">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        <span class="d-none d-sm-inline">Enter Shares</span>
                    </a>
                    <a href="upload_shares.php" class="btn btn-outline-warning d-flex align-items-center">
                        <i class="bi bi-upload me-2"></i>
                        <span class="d-none d-sm-inline">Upload Shares/ETFs</span>
                    </a>
                    <a href="settlement.php" class="btn btn-outline-danger d-flex align-items-center">
                        <i class="bi bi-check-circle me-2"></i>
                        <span class="d-none d-sm-inline">Settle Trades</span>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                       class="btn btn-success d-flex align-items-center" 
                       onclick="return confirm('Export all filtered trades to CSV?')">
                        <i class="bi bi-file-earmark-excel me-2"></i>
                        <span class="d-none d-sm-inline">Export CSV</span>
                    </a>
                    <button type="button" class="btn btn-primary d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#uploadTradeModal">
                        <i class="bi bi-upload me-2"></i>
                        <span class="d-none d-sm-inline">New Trade</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <span class="fw-medium"><?php echo $error_message; ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($show_contract_modal && isset($_SESSION['contract_modal_data'])): 
        $modal_data = $_SESSION['contract_modal_data'];
        
        $stmt = $db->prepare("
            SELECT t.asset_class, t.client_name, COUNT(*) as trade_count, 
                   SUM(quantity) as total_quantity, 
                   SUM(consideration) as total_consideration,
                   t.liberty_mode
            FROM trades t
            WHERE t.client_cds_account = ? 
            AND t.trade_date = ? 
            AND t.trade_side = ? 
            AND t.security_id = ?
            AND t.status = 'active'
            GROUP BY t.asset_class, t.client_name, t.liberty_mode
        ");
        $stmt->execute([
            $modal_data['client_id'], 
            $modal_data['trade_date'], 
            $modal_data['trade_side'], 
            $modal_data['security_id']
        ]);
        $client_data = $stmt->fetch();
        
        $is_equity_or_etf = ($client_data['asset_class'] === 'equity' || $client_data['asset_class'] === 'Exchange Traded Funds');
        $asset_type_display = ($client_data['asset_class'] === 'Exchange Traded Funds') ? 'ETF' : ucfirst($client_data['asset_class']);
        
        $stmt = $db->prepare("
            SELECT trade_reference, quantity, price, consideration, created_at, brokerage_fee_type, final_brokerage_fee, liberty_mode
            FROM trades 
            WHERE client_cds_account = ? 
            AND trade_date = ? 
            AND trade_side = ? 
            AND security_id = ?
            AND status = 'active'
            ORDER BY created_at
        ");
        $stmt->execute([
            $modal_data['client_id'], 
            $modal_data['trade_date'], 
            $modal_data['trade_side'], 
            $modal_data['security_id']
        ]);
        $trades_list = $stmt->fetchAll();
    ?>
    <div class="modal fade show" id="contractNoteModal" tabindex="-1" style="display: block; background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-xl);">
                <div class="modal-header border-0 pb-0" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-color-dark) 100%);">
                    <div class="d-flex align-items-center w-100">
                        <div class="me-3">
                            <i class="bi bi-file-earmark-text-fill" style="font-size: 1.5rem;"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="modal-title mb-0">Generate Contract Note</h5>
                            <small class="opacity-75">Multiple trades detected for <?php echo htmlspecialchars($client_data['client_name']); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="window.location.href='trades';"></button>
                    </div>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 mb-4" style="background-color: #f0f9ff;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-info-circle-fill text-info me-2"></i>
                            <div>
                                <h6 class="alert-heading mb-1">Multiple Trades Detected</h6>
                                <p class="mb-0">Found <strong><?php echo $client_data['trade_count']; ?> trades</strong> for <?php echo htmlspecialchars($client_data['client_name']); ?> on <?php echo format_date($modal_data['trade_date']); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-transparent border-bottom py-3">
                            <h6 class="mb-0 fw-semibold">Trade Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Total Quantity</label>
                                    <div class="fw-semibold fs-5 text-primary"><?php echo number_format($client_data['total_quantity']); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Total Consideration</label>
                                    <div class="fw-semibold fs-5 text-success">TZS <?php echo number_format($client_data['total_consideration'], 2); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Asset Type</label>
                                    <div class="fw-semibold"><?php echo $asset_type_display; ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-muted small mb-1">Trade Side</label>
                                    <div class="fw-semibold badge bg-<?php echo strtolower($modal_data['trade_side']) == 'buy' ? 'success' : 'danger'; ?> px-3 py-2">
                                        <?php echo ucfirst($modal_data['trade_side']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="mb-3 fw-semibold">Individual Trades</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Value</th>
                                        <th>Commission</th>
                                        <th>Liberty Mode</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trades_list as $trade): 
                                        $liberty_display = '';
                                        if ($trade['liberty_mode'] == 'replace_all') $liberty_display = 'Full Rate';
                                        elseif ($trade['liberty_mode'] == 'excess_only') $liberty_display = 'Excess Only';
                                        elseif ($trade['liberty_mode'] == 'tier_override') $liberty_display = 'Tier Override';
                                        else $liberty_display = 'Standard';
                                    ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($trade['trade_reference']); ?></code></td>
                                        <td><?php echo number_format($trade['quantity']); ?></td>
                                                                                </td>
                                        <td>TZS <?php echo number_format($trade['price'], 2); ?></td>
                                        <td>TZS <?php echo number_format($trade['consideration'], 2); ?></td>
                                        <td>TZS <?php echo number_format($trade['final_brokerage_fee'], 2); ?></td>
                                        <td><span class="badge bg-info"><?php echo $liberty_display; ?></span></td>
                                        <td><?php echo date('H:i:s', strtotime($trade['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($modal_data['client_id']); ?>">
                        <input type="hidden" name="trade_date" value="<?php echo htmlspecialchars($modal_data['trade_date']); ?>">
                        <input type="hidden" name="trade_side" value="<?php echo htmlspecialchars($modal_data['trade_side']); ?>">
                        <input type="hidden" name="security_id" value="<?php echo htmlspecialchars($modal_data['security_id']); ?>">
                        <input type="hidden" name="trigger_trade_id" value="<?php echo htmlspecialchars($modal_data['trigger_trade_id']); ?>">
                        
                        <h6 class="mb-3 fw-semibold">Select Contract Note Type</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="card h-100 border-0 shadow-sm option-card" data-option="single">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <i class="bi bi-file-text text-primary" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Single Trade</h6>
                                        <p class="text-muted small mb-0">Generate contract note for only the selected trade</p>
                                        <div class="mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="contract_type" id="contract_type_single" value="single" checked>
                                                <label class="form-check-label fw-medium" for="contract_type_single">Select This</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($is_equity_or_etf): ?>
                            <div class="col-md-4">
                                <div class="card h-100 border-0 shadow-sm option-card" data-option="summary">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <i class="bi bi-file-earmark-bar-graph text-success" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Summary (Equities/ETFs)</h6>
                                        <p class="text-muted small mb-0">Consolidated contract note with one summary page</p>
                                        <div class="mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="contract_type" id="contract_type_summary" value="summary">
                                                <label class="form-check-label fw-medium" for="contract_type_summary">Select This</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-4">
                                <div class="card h-100 border-0 shadow-sm option-card" data-option="detailed">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <i class="bi bi-files text-warning" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Detailed (All)</h6>
                                        <p class="text-muted small mb-0">Separate contract notes for each trade in one PDF</p>
                                        <div class="mt-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="contract_type" id="contract_type_detailed" value="detailed">
                                                <label class="form-check-label fw-medium" for="contract_type_detailed">Select This</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <div class="d-flex justify-content-between">
                                <a href="trades.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg me-1"></i> Cancel
                                </a>
                                <button type="submit" name="generate_contract_note" class="btn btn-primary">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> Generate Contract Note
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const optionCards = document.querySelectorAll('.option-card');
        optionCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.transition = 'transform 0.2s ease';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
            card.addEventListener('click', function() {
                const option = this.getAttribute('data-option');
                const radio = document.querySelector(`#contract_type_${option}`);
                if (radio) {
                    radio.checked = true;
                    optionCards.forEach(c => {
                        c.style.border = '1px solid var(--border-color)';
                        c.style.boxShadow = 'var(--shadow-sm)';
                    });
                    this.style.border = '2px solid var(--primary-color)';
                    this.style.boxShadow = 'var(--shadow-md)';
                }
            });
        });
        const selectedRadio = document.querySelector('input[name="contract_type"]:checked');
        if (selectedRadio) {
            const selectedCard = document.querySelector(`.option-card[data-option="${selectedRadio.value}"]`);
            if (selectedCard) {
                selectedCard.style.border = '2px solid var(--primary-color)';
                selectedCard.style.boxShadow = 'var(--shadow-md)';
            }
        }
    });
    </script>
    
    <?php 
    unset($_SESSION['contract_modal_data']);
    endif; 
    ?>

    <!-- Filters Section (collapsed by default) -->
    <div class="card dashboard-card mb-4">
        <div class="card-header bg-transparent border-0 pb-0">
            <div class="d-flex align-items-center">
                <div class="me-2">
                    <i class="bi bi-funnel text-primary"></i>
                </div>
                <h6 class="mb-0 fw-semibold">Advanced Filters</h6>
                <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
        </div>
        <div class="collapse" id="filterCollapse">
            <div class="card-body">
                <form method="GET" action="" class="row g-4" id="filterForm">
                    <div class="col-lg-2 col-md-4">
                        <label for="status" class="form-label fw-semibold text-dark">Status</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="bi bi-check-circle-fill text-muted"></i>
                            </span>
                            <select class="form-select border-start-0" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="settled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'settled') ? 'selected' : ''; ?>>Settled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="type" class="form-label fw-semibold text-dark">Asset Type</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="bi bi-collection text-muted"></i>
                            </span>
                            <select class="form-select border-start-0" id="type" name="type">
                                <option value="">All Types</option>
                                <option value="bond" <?php echo (isset($_GET['type']) && $_GET['type'] == 'bond') ? 'selected' : ''; ?>>Bonds</option>
                                <option value="equity" <?php echo (isset($_GET['type']) && $_GET['type'] == 'equity') ? 'selected' : ''; ?>>Equities</option>
                                <option value="Exchange Traded Funds" <?php echo (isset($_GET['type']) && $_GET['type'] == 'Exchange Traded Funds') ? 'selected' : ''; ?>>ETFs</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="commission_type" class="form-label fw-semibold text-dark">Commission Type</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="bi bi-percent text-muted"></i>
                            </span>
                            <select class="form-select border-start-0" id="commission_type" name="commission_type">
                                <option value="">All Types</option>
                                <option value="normal" <?php echo (isset($_GET['commission_type']) && $_GET['commission_type'] == 'normal') ? 'selected' : ''; ?>>Normal (Standard)</option>
                                <option value="liberty" <?php echo (isset($_GET['commission_type']) && $_GET['commission_type'] == 'liberty') ? 'selected' : ''; ?>>Liberty (Permanent)</option>
                                <option value="this_trade" <?php echo (isset($_GET['commission_type']) && $_GET['commission_type'] == 'this_trade') ? 'selected' : ''; ?>>This Trade Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label for="trade_date_from" class="form-label fw-semibold text-dark">Trade Date From</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="bi bi-calendar text-muted"></i>
                            </span>
                            <input type="date" class="form-control border-start-0" id="trade_date_from" name="trade_date_from" 
                                   value="<?php echo isset($_GET['trade_date_from']) ? htmlspecialchars($_GET['trade_date_from']) : ''; ?>">
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label for="trade_date_to" class="form-label fw-semibold text-dark">Trade Date To</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="bi bi-calendar text-muted"></i>
                            </span>
                            <input type="date" class="form-control border-start-0" id="trade_date_to" name="trade_date_to" 
                                   value="<?php echo isset($_GET['trade_date_to']) ? htmlspecialchars($_GET['trade_date_to']) : ''; ?>">
                        </div>
                    </div>
                    <div class="col-lg-12">
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel me-1"></i> Apply Filters
                            </button>
                            <a href="trades.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!--AJAX_TABLE_START-->
    <!-- Trades Table -->
    <div class="card dashboard-card" id="trades-card">
        <div class="card-header bg-transparent border-0 pb-0">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="me-2">
                        <i class="bi bi-table text-primary"></i>
                    </div>
                    <h6 class="mb-0 fw-semibold">Trading Portfolio</h6>
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary px-3 py-2 me-2" id="tradeCount"><?php echo number_format($total_trades); ?> Total Trades</span>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                                   onclick="return confirm('Export all filtered trades to CSV?')">
                                    <i class="bi bi-file-earmark-excel me-2"></i>Export to CSV
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col">
                    <div class="position-relative">
                        <input type="text" class="form-control form-control-sm ps-5" id="liveSearch" 
                               placeholder="Live search across all columns..." autocomplete="off"
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" style="font-size:0.8rem"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="bi bi-graph-up text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                    </div>
                    <h5 class="text-muted mb-3">No Trades Found</h5>
                    <p class="text-muted mb-4">Start building your trading portfolio by uploading your first trade.</p>
                    <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#uploadTradeModal">
                        <i class="bi bi-upload me-2"></i> Upload Your First Trade
                    </button>
                </div>
            <?php else: ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <?php echo number_format($showing_from); ?> to <?php echo number_format($showing_to); ?> of <?php echo number_format($total_trades); ?> trades
                            </small>
                        </div>
                        <div class="d-flex align-items-center">
                            <label for="pageSize" class="form-label mb-0 me-2 small">Show:</label>
                            <select id="pageSize" class="form-select form-select-sm" style="width: auto;">
                                <option value="10" <?php echo $per_page === 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $per_page === 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $per_page === 100 ? 'selected' : ''; ?>>100</option>
                                <option value="500" <?php echo $per_page === 500 ? 'selected' : ''; ?>>500</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tradesTable">
                        <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                            <tr>
                                <th class="border-0 fw-semibold text-dark py-3">Reference</th>
                                <th class="border-0 fw-semibold text-dark py-3">Instrument</th>
                                <th class="border-0 fw-semibold text-dark py-3">Asset Type</th>
                                <th class="border-0 fw-semibold text-dark py-3">Side</th>
                                <th class="border-0 fw-semibold text-dark py-3">Quantity</th>
                                <th class="border-0 fw-semibold text-dark py-3">Price</th>
                                <th class="border-0 fw-semibold text-dark py-3">Total Value</th>
                                <th class="border-0 fw-semibold text-dark py-3">Commission</th>
                                <th class="border-0 fw-semibold text-dark py-3">Commission Type</th>
                                <th class="border-0 fw-semibold text-dark py-3">Liberty Mode</th>
                                <th class="border-0 fw-semibold text-dark py-3">Client</th>
                                <th class="border-0 fw-semibold text-dark py-3">Trade Date</th>
                                <th class="border-0 fw-semibold text-dark py-3">Status</th>
                                <th class="border-0 fw-semibold text-dark py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trades as $trade): 
                                $asset_class_icon = '';
                                $asset_class_badge = '';
                                
                                if ($trade['asset_class'] === 'bond') {
                                    $asset_class_icon = 'bi-bank';
                                    $asset_class_badge = 'bg-warning';
                                } elseif ($trade['asset_class'] === 'Exchange Traded Funds') {
                                    $asset_class_icon = 'bi-pie-chart';
                                    $asset_class_badge = 'bg-purple';
                                } else {
                                    $asset_class_icon = 'bi-graph-up';
                                    $asset_class_badge = 'bg-info';
                                }
                                
                                $commission_badge = '';
                                $commission_icon = '';
                                if ($trade['brokerage_fee_type'] == 'normal') {
                                    $commission_badge = 'bg-secondary';
                                    $commission_icon = 'bi-building';
                                } elseif ($trade['brokerage_fee_type'] == 'liberty') {
                                    $commission_badge = 'bg-warning';
                                    $commission_icon = 'bi-star-fill';
                                } else {
                                    $commission_badge = 'bg-info';
                                    $commission_icon = 'bi-tag-fill';
                                }
                                
                                // Determine liberty mode display
                                $liberty_mode_display = 'Standard';
                                if ($trade['brokerage_fee_type'] == 'liberty' || $trade['brokerage_fee_type'] == 'this_trade') {
                                    $mode = $trade['liberty_mode'] ?? ($trade['client_liberty_mode'] ?? 'replace_all');
                                    if ($mode == 'replace_all') {
                                        $liberty_mode_display = 'Full Rate';
                                    } elseif ($mode == 'excess_only') {
                                        $liberty_mode_display = 'Excess Only';
                                    } elseif ($mode == 'tier_override') {
                                        $liberty_mode_display = 'Tier Override';
                                    }
                                }
                            ?>
                                <tr class="border-bottom">
                                    <td class="border-0 py-3">
                                        <div class="fw-semibold text-primary"><?php echo htmlspecialchars($trade['trade_reference']); ?></div>
                                        <small class="text-muted">ID: <?php echo $trade['id']; ?></small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($trade['security_id']); ?></div>
                                            <small class="text-muted">
                                                <i class="bi <?php echo $asset_class_icon; ?> me-1"></i>
                                                <?php 
                                                if ($trade['asset_class'] === 'Exchange Traded Funds') {
                                                    echo 'ETF';
                                                } else {
                                                    echo ucfirst($trade['asset_class']);
                                                }
                                                ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge <?php echo $asset_class_badge; ?> px-3 py-2">
                                            <i class="bi <?php echo $asset_class_icon; ?> me-1"></i>
                                            <?php 
                                            if ($trade['asset_class'] === 'Exchange Traded Funds') {
                                                echo 'ETF';
                                            } else {
                                                echo ucfirst($trade['asset_class']);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge bg-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'success' : 'danger'; ?> px-3 py-2">
                                            <i class="bi bi-arrow-<?php echo strtolower($trade['trade_side']) == 'buy' ? 'down' : 'up'; ?> me-1"></i>
                                            <?php echo ucfirst($trade['trade_side']); ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-medium"><?php echo number_format($trade['quantity']); ?></div>
                                        <small class="text-muted">Units</small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-medium">TZS <?php echo number_format($trade['price'], 2); ?></div>
                                        <small class="text-muted">Per unit</small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-semibold text-success">TZS <?php echo number_format($trade['consideration'], 2); ?></div>
                                        <small class="text-muted">Total value</small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-semibold">TZS <?php echo number_format($trade['final_brokerage_fee'], 2); ?></div>
                                        <small class="text-muted">Commission</small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge <?php echo $commission_badge; ?> px-3 py-2">
                                            <i class="bi <?php echo $commission_icon; ?> me-1"></i>
                                            <?php 
                                            if ($trade['brokerage_fee_type'] == 'normal') {
                                                echo 'Normal';
                                            } elseif ($trade['brokerage_fee_type'] == 'liberty') {
                                                echo 'Liberty';
                                            } else {
                                                echo 'This Trade';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge bg-info px-3 py-2">
                                            <?php echo $liberty_mode_display; ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div>
                                            <?php if ($trade['client_id']): ?>
                                                <a href="client_profile?id=<?php echo $trade['client_id']; ?>" 
                                                   class="fw-medium text-decoration-none text-primary hover-underline">
                                                    <?php echo htmlspecialchars($trade['proper_client_name'] ?? $trade['client_name']); ?>
                                                    <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="fw-medium"><?php echo htmlspecialchars($trade['client_name']); ?></span>
                                            <?php endif; ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($trade['client_cds_account']); ?></small>
                                        </div>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="fw-medium"><?php echo format_date($trade['trade_date']); ?></div>
                                        <small class="text-muted">Settlement: <?php echo format_date($trade['settlement_date']); ?></small>
                                    </td>
                                    <td class="border-0 py-3">
                                        <span class="badge bg-<?php 
                                            echo $trade['status'] == 'active' ? 'success' : 
                                                ($trade['status'] == 'cancelled' ? 'danger' : 'info'); 
                                        ?> px-3 py-2">
                                            <i class="bi bi-<?php 
                                                echo $trade['status'] == 'active' ? 'check-circle' : 
                                                    ($trade['status'] == 'cancelled' ? 'x-circle' : 'check-all'); 
                                            ?> me-1"></i>
                                            <?php echo ucfirst($trade['status']); ?>
                                        </span>
                                    </td>
                                    <td class="border-0 py-3">
                                        <div class="btn-group">
                                            <?php if ($trade['status'] == 'active'): ?>
                                                <a href="?action=cancel&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-danger btn-sm" 
                                                   onclick="return confirm('Cancel this trade?')"
                                                   title="Cancel Trade">
                                                    <i class="bi bi-x-lg"></i>
                                                </a>
                                            <?php elseif ($trade['status'] == 'cancelled'): ?>
                                                <a href="?action=enable&id=<?php echo $trade['id']; ?>" 
                                                   class="btn btn-outline-success btn-sm" 
                                                   onclick="return confirm('Enable this trade?')"
                                                   title="Enable Trade">
                                                    <i class="bi bi-check-lg"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="?action=contract_note&id=<?php echo $trade['id']; ?>" 
                                               class="btn btn-outline-primary btn-sm" 
                                               title="Generate Contract Note">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </a>
                                            <a href="view_trade?id=<?php echo $trade['id']; ?>" 
                                               class="btn btn-outline-secondary btn-sm" 
                                               title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="p-3 border-top">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <?php echo number_format($showing_from); ?> to <?php echo number_format($showing_to); ?> of <?php echo number_format($total_trades); ?> trades
                            </small>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php $previous_disabled = $page <= 1; ?>
                                <li class="page-item <?php echo $previous_disabled ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $previous_disabled ? '#' : buildTradeListUrl(['page' => $page - 1]); ?>">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                if ($start_page > 1):
                                ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo buildTradeListUrl(['page' => 1]); ?>">1</a></li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($page_number = $start_page; $page_number <= $end_page; $page_number++): ?>
                                    <li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo buildTradeListUrl(['page' => $page_number]); ?>"><?php echo $page_number; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo buildTradeListUrl(['page' => $total_pages]); ?>"><?php echo $total_pages; ?></a></li>
                                <?php endif; ?>

                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page >= $total_pages ? '#' : buildTradeListUrl(['page' => $page + 1]); ?>">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
    <!--AJAX_TABLE_END-->

    <!-- Upload Trade Modal -->
<div class="modal fade" id="uploadTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-xl); border: none; box-shadow: var(--shadow-xl);">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h4 class="modal-title fw-bold text-primary mb-1">Upload New Trade</h4>
                        <p class="text-muted small mb-0">Enter trade details for processing</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="trade_type" class="form-label fw-semibold">Asset Type</label>
                            <select class="form-select" id="trade_type" name="asset_class" required onchange="updateInstruments()">
                                <option value="">Select Asset Type</option>
                                <option value="bond">Bond</option>
                                <option value="equity">Equity</option>
                                <option value="Exchange Traded Funds">ETF</option>
                            </select>
                            <div class="invalid-feedback">Please select an asset type.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="security_id" class="form-label fw-semibold">Security/Instrument</label>
                            <input type="text" class="form-control" id="security_id" name="security_id" 
                                   placeholder="Enter security identifier" required>
                            <div class="invalid-feedback">Please enter a security identifier.</div>
                            <small class="form-text text-muted" id="security-help">Enter the unique security identifier</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="trade_side" class="form-label fw-semibold">Trade Side</label>
                            <select class="form-select" id="trade_side" name="trade_side" required>
                                <option value="">Select Side</option>
                                <option value="buy">Buy</option>
                                <option value="sell">Sell</option>
                            </select>
                            <div class="invalid-feedback">Please select trade side.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="trade_reference" class="form-label fw-semibold">Trade Reference</label>
                            <input type="text" class="form-control" id="trade_reference" name="trade_reference" 
                                   placeholder="e.g., TRD-20240101-001" required>
                            <div class="invalid-feedback">Please enter a trade reference.</div>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="quantity" class="form-label fw-semibold">Quantity</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" 
                                   min="1" step="1" placeholder="e.g., 1000" required oninput="calculateTotal()">
                            <div class="invalid-feedback">Please enter a valid quantity.</div>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="price" class="form-label fw-semibold">Price per Unit (TZS)</label>
                            <input type="number" class="form-control" id="price" name="price" 
                                   min="0" step="0.01" placeholder="e.g., 2500.50" required oninput="calculateTotal()">
                            <div class="invalid-feedback">Please enter a valid price.</div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Consideration</label>
                            <div class="form-control bg-light border-0">
                                <span class="fw-semibold text-success" id="total-consideration">TZS 0.00</span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="client_name" class="form-label fw-semibold">Client</label>
                            <select class="form-select" id="client_name" name="client_name" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo htmlspecialchars($client['client_name']); ?>" 
                                            data-cds="<?php echo htmlspecialchars($client['cds_account']); ?>">
                                        <?php echo htmlspecialchars($client['client_name']); ?> (<?php echo htmlspecialchars($client['cds_account']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a client.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="client_cds_account" class="form-label fw-semibold">Client CDS Account</label>
                            <input type="text" class="form-control" id="client_cds_account" name="client_cds_account" 
                                   placeholder="e.g., CDS001234" required>
                            <div class="invalid-feedback">Please enter client CDS account.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="counterparty_name" class="form-label fw-semibold">Counterparty</label>
                            <select class="form-select" id="counterparty_name" name="counterparty_name" required>
                                <option value="">Select Counterparty</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo htmlspecialchars($company['company_name']); ?>"
                                            data-code="<?php echo htmlspecialchars($company['company_code']); ?>">
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a counterparty.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="counterparty_cds_account" class="form-label fw-semibold">Counterparty CDS Account</label>
                            <input type="text" class="form-control" id="counterparty_cds_account" name="counterparty_cds_account" 
                                   placeholder="e.g., CDS005678" required>
                            <div class="invalid-feedback">Please enter counterparty CDS account.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="trade_date" class="form-label fw-semibold">Trade Date</label>
                            <input type="date" class="form-control" id="trade_date" name="trade_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="invalid-feedback">Please enter trade date.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="settlement_date" class="form-label fw-semibold">Settlement Date</label>
                            <input type="date" class="form-control" id="settlement_date" name="settlement_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>" required>
                            <div class="invalid-feedback">Please enter settlement date.</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 p-3 bg-light rounded">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-info-circle text-primary me-2 fs-5"></i>
                            <div>
                                <small class="text-muted">
                                    <strong>Note:</strong> For bonds, brokerage fee is calculated on Face Value with tiered structure:
                                    First 100M at 0.063132%, excess at 0.035%. VAT is 18% on brokerage.
                                    CMSA (0.01% on consideration), CDS (0.0118% on face value), DSE (0.02006% on face value).
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" name="upload_trade" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i> Upload Trade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.badge.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}

.hover-underline:hover {
    text-decoration: underline !important;
}

.page-item.active .page-link {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

.page-link {
    color: var(--primary-color);
}

.page-link:hover {
    color: var(--primary-color-dark);
    background-color: #f8f9fa;
}

.option-card {
    cursor: pointer;
    transition: all 0.2s ease;
}

.option-card:hover {
    border-color: var(--primary-color) !important;
}

input[type="number"] {
    -moz-appearance: textfield;
}

input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const contractModal = document.getElementById('contractNoteModal');
    if (contractModal) {
        contractModal.addEventListener('click', function(e) {
            if (e.target === this) {
                window.location.href = 'trades';
            }
        });
    }
    
    const pageSizeSelect = document.getElementById('pageSize');
    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }
    
    const clientSelect = document.getElementById('client_name');
    const clientCdsAccount = document.getElementById('client_cds_account');
    
    if (clientSelect && clientCdsAccount) {
        clientSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const cdsAccount = selectedOption.getAttribute('data-cds');
                if (cdsAccount) {
                    clientCdsAccount.value = cdsAccount;
                }
            } else {
                clientCdsAccount.value = '';
            }
        });
    }
    
    const counterpartySelect = document.getElementById('counterparty_name');
    const counterpartyCdsAccount = document.getElementById('counterparty_cds_account');
    
    if (counterpartySelect && counterpartyCdsAccount) {
        counterpartySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const companyCode = selectedOption.getAttribute('data-code');
                if (companyCode) {
                    counterpartyCdsAccount.value = companyCode;
                }
            } else {
                counterpartyCdsAccount.value = '';
            }
        });
    }
});

function updateInstruments() {
    const tradeType = document.getElementById('trade_type').value;
    const securityInput = document.getElementById('security_id');
    const helpText = document.getElementById('security-help');
    
    securityInput.disabled = false;
    
    if (tradeType === 'bond') {
        securityInput.placeholder = 'e.g., 675-15-T16-A1';
        helpText.textContent = 'Enter bond ATS code';
    } else if (tradeType === 'equity') {
        securityInput.placeholder = 'e.g., WVSL, CRDB';
        helpText.textContent = 'Enter stock symbol';
    } else if (tradeType === 'Exchange Traded Funds') {
        securityInput.placeholder = 'e.g., ETF001';
        helpText.textContent = 'Enter ETF identifier';
    } else {
        securityInput.placeholder = 'Enter security identifier';
        helpText.textContent = 'Enter the unique security identifier';
    }
}

function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const price = parseFloat(document.getElementById('price').value) || 0;
    const total = quantity * price;
    
    document.getElementById('total-consideration').textContent = 'TZS ' + total.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();

// Live search AJAX handler
let searchTimeout;
const liveSearch = document.getElementById('liveSearch');
const tableCard = document.getElementById('trades-card');
const tradeCount = document.getElementById('tradeCount');

function reloadTable() {
    const url = new URL(window.location);
    const searchVal = liveSearch ? liveSearch.value.trim() : '';
    if (searchVal) {
        url.searchParams.set('search', searchVal);
    } else {
        url.searchParams.delete('search');
    }
    url.searchParams.set('ajax', '1');
    url.searchParams.set('page', '1');
    
    fetch(url.toString())
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const newHtml = data.html;
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = newHtml;
                const newCardBody = tempDiv.querySelector('.card-body');
                const newPagination = tempDiv.querySelector('.p-3.border-top');
                const oldCardBody = tableCard.querySelector('.card-body');
                const oldPagination = tableCard.querySelector('.p-3.border-top');
                if (newCardBody && oldCardBody) oldCardBody.replaceWith(newCardBody);
                if (newPagination) {
                    if (oldPagination) oldPagination.replaceWith(newPagination);
                    else tableCard.appendChild(newPagination);
                } else if (oldPagination) {
                    oldPagination.remove();
                }
                if (tradeCount) tradeCount.textContent = Number(data.total).toLocaleString() + ' Total Trades';
                const cleanUrl = new URL(window.location);
                cleanUrl.searchParams.delete('ajax');
                window.history.replaceState({}, '', cleanUrl);
            }
        })
        .catch(() => {});
}

if (liveSearch) {
    liveSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(reloadTable, 300);
    });
    // Listen for filter form submits to also reload ajax
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formUrl = new URL(this.action, window.location.origin);
            const formData = new FormData(this);
            formData.forEach((val, key) => {
                if (val) formUrl.searchParams.set(key, val);
                else formUrl.searchParams.delete(key);
            });
            // Preserve live search value
            const searchVal = liveSearch ? liveSearch.value.trim() : '';
            if (searchVal) formUrl.searchParams.set('search', searchVal);
            window.location.href = formUrl.toString();
        });
    }
}
</script>

<?php
if ($is_ajax) {
    $full_html = ob_get_clean();
    // Extract table fragment between markers
    preg_match('/<!--AJAX_TABLE_START-->(.*?)<!--AJAX_TABLE_END-->/s', $full_html, $matches);
    $table_html = $matches[1] ?? '';
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $table_html,
        'total' => $total_trades,
        'showing_from' => $showing_from,
        'showing_to' => $showing_to,
        'total_pages' => $total_pages,
        'page' => $page
    ]);
    exit;
}
include '../includes/footer.php'; ?>
                                        
                                        
                                        
