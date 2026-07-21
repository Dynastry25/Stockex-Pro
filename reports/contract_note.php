<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$db = getDBConnection();
$master_data = loadMasterData($db);

// Get form data
$client = $_POST['client'] ?? '';
$agent = $_POST['agent'] ?? '';
$broker = $_POST['broker'] ?? '';
$security = $_POST['security'] ?? '';
$type = $_POST['type'] ?? '';
$user = $_POST['user'] ?? '';
$status = $_POST['status'] ?? '';
$period_from = $_POST['period_from'] ?? '';
$period_to = $_POST['period_to'] ?? '';
$asset_class = $_POST['asset_class'] ?? ''; // New asset_class filter
$contract_type = $_POST['contract_type'] ?? 'client';
$report_type = $_POST['report_type'] ?? 'detailed';
$contract_grouping = $_POST['contract_grouping'] ?? 'individual';
$report_by = $_POST['report_by'] ?? 'Month';
$orientation = $_POST['orientation'] ?? 'landscape';
$watermark = $_POST['watermark'] ?? 'no';
$export = $_GET['export'] ?? $_POST['export'] ?? '';

$where_conditions = [];
$params = [];

if (!empty($client)) {
    if (validateClient($client, $master_data)) {
        $where_conditions[] = "client_cds_account = ?";
        $params[] = $client;
    }
}
if (!empty($broker)) {
    if (validateBroker($broker, $master_data)) {
        $where_conditions[] = "broker_name = ?";
        $params[] = $broker;
    }
}
if (!empty($security)) {
    if (validateSecurity($security, $master_data)) {
        $where_conditions[] = "security_id = ?";
        $params[] = $security;
    }
}
if (!empty($type)) {
    if (validateTransactionType($type, $master_data)) {
        $where_conditions[] = "trade_side = ?";
        $params[] = $type;
    }
}
if (!empty($status)) {
    if (validateStatus($status, $master_data)) {
        $where_conditions[] = "status = ?";
        $params[] = $status;
    }
}
if (!empty($asset_class)) {
    if (validateAssetClass($asset_class)) {
        $where_conditions[] = "asset_class = ?";
        $params[] = $asset_class;
    }
}
if (!empty($period_from)) {
    $where_conditions[] = "trade_date >= ?";
    $params[] = $period_from;
}
if (!empty($period_to)) {
    $where_conditions[] = "trade_date <= ?";
    $params[] = $period_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get trades data with enhanced joins to master data
$sql = "SELECT t.*, 
               bt.description as bond_type_desc, bi.description as issuer_desc, 
               bes.description as economic_sector_desc, pf.description as payment_freq_desc,
               st.description as share_type_desc, smt.description as market_trend_desc,
               tt.description as title_desc, it.description as identity_type_desc
        FROM trades t
        LEFT JOIN bonds b ON t.security_id = b.security_id AND t.asset_class = 'bond'
        LEFT JOIN bond_types bt ON b.security_type = bt.code
        LEFT JOIN bond_issuers bi ON b.issuer = bi.code
        LEFT JOIN bonds_economic_sectors bes ON b.economic_sector = bes.code
        
        LEFT JOIN payment_frequencies pf ON b.payment_frequency = pf.code
        LEFT JOIN equities e ON t.security_id = e.security_id AND t.asset_class = 'equity'
        LEFT JOIN share_types st ON e.share_type = st.code
        LEFT JOIN share_market_trends smt ON e.market_trend = smt.code
        LEFT JOIN titles tt ON t.client_title = tt.code
        LEFT JOIN identity_types it ON t.client_identity_type = it.code
        $where_clause 
        ORDER BY trade_date DESC, created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$trades = $stmt->fetchAll();

// Handle export requests
if ($export === 'pdf') {
    generatePDFReport($trades, $report_type, $master_data);
    exit;
} elseif ($export === 'excel') {
    generateExcelReport($trades, $report_type, $master_data);
    exit;
}

// Generate reports with master data context
if ($contract_type === 'client') {
    generateContractNotes($trades, $report_type, $watermark, $master_data, $contract_grouping);
} elseif ($contract_type === 'agent') {
    generateTransactionSummaryReports($trades, $report_type, $report_by, $master_data);
} elseif ($contract_type === 'broker') {
    generateBrokerCustodianReports($trades, $report_type, $report_by, $master_data);
} elseif ($contract_type === 'ledger') {
    generateLedgerEntries($trades, $report_type, $report_by, $master_data);
} else {
    generateSummaryReports($trades, $report_type, $report_by, $master_data);
}

function loadMasterData($db) {
    $master_data = [];
    $tables = [
        'bond_types', 'bond_issuers', 'bonds_economic_sectors', 'payment_frequencies',
        'share_types', 'share_market_trends', 'titles', 'identity_types',
        'transaction_types', 'payment_methods', 'ledger_types', 'companies',
        'custodians', 'brokers', 'gl_account_types', 'gl_account_formats'
    ];
    foreach ($tables as $table) {
        $stmt = $db->query("SELECT * FROM $table WHERE status = 'active' ORDER BY priority");
        $master_data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get distinct clients for filter
    $stmt = $db->query("SELECT DISTINCT client_cds_account, client_name FROM trades WHERE client_name IS NOT NULL AND client_name != '' ORDER BY client_name");
    $master_data['clients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get distinct securities for filter
    $stmt = $db->query("SELECT DISTINCT security_id, asset_class FROM trades WHERE security_id IS NOT NULL ORDER BY security_id");
    $master_data['securities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get distinct asset classes for filter
    $stmt = $db->query("SELECT DISTINCT asset_class FROM trades WHERE asset_class IS NOT NULL ORDER BY asset_class");
    $master_data['asset_classes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $master_data;
}

function validateClient($client, $master_data) {
    return !empty($client);
}

function validateBroker($broker, $master_data) {
    foreach ($master_data['brokers'] as $b) {
        if ($b['account'] === $broker || $b['name'] === $broker) {
            return true;
        }
    }
    return false;
}

function validateSecurity($security, $master_data) {
    return !empty($security);
}

function validateTransactionType($type, $master_data) {
    foreach ($master_data['transaction_types'] as $tt) {
        if ($tt['code'] === $type || $tt['description'] === $type) {
            return true;
        }
    }
    return false;
}

function validateStatus($status, $master_data) {
    $valid_statuses = ['active', 'pending', 'cancelled', 'settled'];
    return in_array($status, $valid_statuses);
}

function validateAssetClass($asset_class) {
    $valid_asset_classes = ['equity', 'bond', 'treasury_bill', 'corporate_bond', 'treasury_bond'];
    return in_array($asset_class, $valid_asset_classes);
}

function getFeeConfiguration($fee_type, $applies_to = 'ALL') {
    global $db;
    if (!$db) $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT rate_percentage, fixed_amount, calculation_base 
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

function calculateBankCharges($consideration) {
    if ($consideration < 100000) return 250;
    if ($consideration < 10000000) return 2000;
    if ($consideration < 50000000) return 6000;
    return 12000;
}

function calculateFees($amount, $consideration, $asset_class, $master_data) {
    $asset_type_map = [
        'equity' => 'EQUITY',
        'bond' => 'BOND',
        'treasury_bill' => 'TREASURY_BILL',
        'corporate_bond' => 'CORPORATE_BOND',
        'treasury_bond' => 'TREASURY_BOND'
    ];
    
    $applies_to = $asset_type_map[$asset_class] ?? 'ALL';
    
    // For Treasury bonds specifically - use calculateTreasuryBondFees
    if ($asset_class === 'treasury_bond' || $asset_class === 'bond') {
        return calculateTreasuryBondFees($amount, $consideration);
    }
    
    // Existing fee calculation for other asset classes (equities)
    $brokerage_config = getFeeConfiguration('BROKERAGE', $applies_to);
    $vat_config = getFeeConfiguration('VAT', 'ALL');
    $cmsa_config = getFeeConfiguration('CMSA', 'ALL');
    $dse_config = getFeeConfiguration('DSE', 'ALL');
    $fidelity_config = getFeeConfiguration('FIDELITY', 'equities');
    $cds_config = getFeeConfiguration('CSDR', 'ALL');
    
    $brokerage_rate = $brokerage_config['rate_percentage'] ?? 1.7;
    $brokerage_commission = $amount * ($brokerage_rate / 100);
    
    $vat_rate = $vat_config['rate_percentage'] ?? 18.0;
    $vat_on_brokerage = $brokerage_commission * ($vat_rate / 100);
    
    $cmsa_rate = $cmsa_config['rate_percentage'] ?? 0.14;
    $cmsa_fee = $consideration * ($cmsa_rate / 100);
    
    $dse_rate = $dse_config['rate_percentage'] ?? 0.1652;
    $dse_fee = $amount * ($dse_rate / 100);
    
    $fidelity_rate = $fidelity_config['rate_percentage'] ?? 0.02;
    $fidelity_fee = $amount * ($fidelity_rate / 100);
    
    $cds_rate = $cds_config['rate_percentage'] ?? 0.0708;
    $cds_fee = $amount * ($cds_rate / 100);
    
    $bank_charges = calculateBankCharges($consideration);
    
    return [
        'brokerage_commission' => $brokerage_commission,
        'vat_on_brokerage' => $vat_on_brokerage,
        'cmsa_fee' => $cmsa_fee,
        'dse_fee' => $dse_fee,
        'fidelity_fee' => $fidelity_fee,
        'cds_fee' => $cds_fee,
        'csdr_fee' => 0.00,
        'bank_charges' => $bank_charges,
        'total_charges' => $brokerage_commission + $vat_on_brokerage + $cmsa_fee + $dse_fee + $fidelity_fee + $cds_fee + $bank_charges
    ];
}

function calculateTreasuryBondFees($quantity, $consideration) {
    // Treasury Bond specific fees as per your requirements
    $brokerage_rate = 0.02500; // 0.02500% of total quantity
    $vat_rate = 18.00; // 18% of brokerage commission
    $cmsa_rate = 0.01; // 0.01% of consideration
    $csdr_rate = 0.0118; // 0.0118% of quantity (VAT included)
    $dse_rate = 0.02006; // 0.02006% of quantity
    
    // Calculate individual fees
    $brokerage_commission = $quantity * ($brokerage_rate / 100);
    $vat_on_brokerage = $brokerage_commission * ($vat_rate / 100);
    $cmsa_fee = $consideration * ($cmsa_rate / 100);
    $csdr_fee = $quantity * ($csdr_rate / 100); // VAT already included
    $dse_fee = $quantity * ($dse_rate / 100);
    
    $bank_charges = calculateBankCharges($consideration);
    $total_charges = $brokerage_commission + $vat_on_brokerage + $cmsa_fee + $csdr_fee + $dse_fee + $bank_charges;
    
    return [
        'brokerage_commission' => $brokerage_commission,
        'vat_on_brokerage' => $vat_on_brokerage,
        'cmsa_fee' => $cmsa_fee,
        'csdr_fee' => $csdr_fee,
        'dse_fee' => $dse_fee,
        'fidelity_fee' => 0.00,
        'cds_fee' => 0.00,
        'bank_charges' => $bank_charges,
        'total_charges' => $total_charges
    ];
}

function generateContractNotes($trades, $report_type, $watermark, $master_data, $contract_grouping = 'individual') {
    global $db;
    
    if (empty($trades)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No trades found</h5>
                <p class="text-muted">No trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    if ($contract_grouping === 'summary') {
        $grouped_trades = [];
        foreach ($trades as $trade) {
            $key = $trade['client_cds_account'] . '|' . $trade['trade_date'] . '|' . $trade['security_id'] . '|' . $trade['trade_side'];
            if (!isset($grouped_trades[$key])) {
                $grouped_trades[$key] = [];
            }
            $grouped_trades[$key][] = $trade;
        }
        
        foreach ($grouped_trades as $group) {
            generateSummaryContractNote($group, $watermark, $master_data);
            if (count($grouped_trades) > 1) {
                echo '<div style="page-break-after: always;"></div>';
            }
        }
    } else {
        foreach ($trades as $trade) {
            if ($trade['asset_class'] === 'bond') {
                generateBondContractNote($trade, $watermark, $master_data);
            } else {
                generateEquityContractNote($trade, $watermark, $master_data);
            }
            if (count($trades) > 1) {
                echo '<div style="page-break-after: always;"></div>';
            }
        }
    }
}

function generateSummaryContractNote($trades, $watermark, $master_data) {
    if (empty($trades)) return;
    
    $first_trade = $trades[0];
    $total_quantity = 0;
    $total_consideration = 0;
    $total_charges = 0;
    
    foreach ($trades as $trade) {
        $total_quantity += floatval($trade['quantity']);
        $total_consideration += floatval($trade['consideration']);
        
        // Use appropriate calculation based on asset class
        if ($trade['asset_class'] === 'bond') {
            $fees = calculateTreasuryBondFees(floatval($trade['quantity']), floatval($trade['consideration']));
        } else {
            $fees = calculateFees(floatval($trade['price']), floatval($trade['consideration']), $trade['asset_class'], $master_data);
        }
        $total_charges += $fees['total_charges'];
    }
    
    $average_price = $total_quantity > 0 ? $total_consideration / $total_quantity : 0;
    
    // FIXED: Use consistent trade side detection
    $trade_side_upper = strtoupper(trim($first_trade['trade_side']));
    
    // CORRECTED: For SELL trades, deduct charges; for BUY trades, add charges
    $net_amount = $trade_side_upper === 'SELL' ? 
        $total_consideration - $total_charges : 
        $total_consideration + $total_charges;
    
    // FIXED: Use first_trade for contract number generation
    $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($first_trade['id'], 6, '0', STR_PAD_LEFT);
    $order_number = str_pad($first_trade['id'], 6, '0', STR_PAD_LEFT);
    $exchange_ref = date('ymd', strtotime($first_trade['trade_date'])) . str_pad($first_trade['id'], 3, '0', STR_PAD_LEFT);
    
    if ($first_trade['asset_class'] === 'bond') {
        generateBondContractNoteHTML($first_trade, $contract_number, $order_number, $exchange_ref, 
                                   $total_quantity, $average_price, $total_consideration, $total_charges, $net_amount, $watermark, true);
    } else {
        generateEquityContractNoteHTML($first_trade, $contract_number, $order_number, $exchange_ref, 
                                     $total_quantity, $average_price, $total_consideration, $total_charges, $net_amount, $watermark, true);
    }
}

function generateEquityContractNote($trade, $watermark, $master_data) {
    $consideration = floatval($trade['consideration']);
    $price = floatval($trade['price']);

    $fees = calculateFees($price, $consideration, $trade['asset_class'], $master_data);
    
    // FIXED: Use consistent trade side detection
    $trade_side_upper = strtoupper(trim($trade['trade_side']));
    
    // CORRECTED: For SELL trades, deduct charges; for BUY trades, add charges
    $net_amount = $trade_side_upper === 'SELL' ? 
        $consideration - $fees['total_charges'] : 
        $consideration + $fees['total_charges'];
    
    // FIXED: Use current trade for contract number generation
    $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
    
    generateEquityContractNoteHTML($trade, $contract_number, $order_number, $exchange_ref, 
                                 $trade['quantity'], $trade['price'], $consideration, $fees['total_charges'], $net_amount, $watermark);
}

function generateBondContractNote($trade, $watermark, $master_data) {
    $consideration = floatval($trade['consideration']);
    $quantity = floatval($trade['quantity']);
    
    // Use Treasury bond specific fee calculation
    $fees = calculateTreasuryBondFees($quantity, $consideration);
    
    // FIXED: Use consistent trade side detection
    $trade_side_upper = strtoupper(trim($trade['trade_side']));
    
    // CORRECTED: For SELL trades, deduct charges; for BUY trades, add charges
    $net_amount = $trade_side_upper === 'SELL' ? 
        $consideration - $fees['total_charges'] : 
        $consideration + $fees['total_charges'];
    
    // FIXED: Use current trade for contract number generation
    $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
    $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);
    
    generateBondContractNoteHTML($trade, $contract_number, $order_number, $exchange_ref, 
                               $trade['quantity'], $trade['price'], $consideration, $fees['total_charges'], $net_amount, $watermark);
}

function generateEquityContractNoteHTML($trade, $contract_number, $order_number, $exchange_ref, $quantity, $price, $consideration, $total_charges, $net_amount, $watermark, $is_summary = false) {
    // FIXED: Use consistent trade side detection
    $trade_side_upper = strtoupper(trim($trade['trade_side']));
    $amount_label = $trade_side_upper === 'SELL' ? 'RECEIVABLE' : 'PAYABLE';
    ?>
    <div class="contract-note" style="font-family: Arial, sans-serif; font-size: 11px; line-height: 1.3; max-width: 800px; margin: 0 auto; background: white; padding: 20px;">
       

   <div class="report-header" style="margin-bottom: 15px; font-family: Arial, sans-serif;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 80px; vertical-align: middle; text-align: center;">
                        <img src="../assets/HeaderLogoVfsl.jpg" alt="Victory Financial Services" style="height: 55px; width: auto;">
                    </td>
                    <td style="vertical-align: middle; padding-left: 12px;">
                        <div style="color: #042D92; font-size: 16px; font-weight: bold;">VICTORY FINANCIAL SERVICES LIMITED</div>
                        <div style="color: #FF0000; font-size: 11px; font-weight: bold; font-style: italic;">Stockbroker/Dealer, Fund Manager &amp; Investment Advisor</div>
                        <div style="color: #042D92; font-size: 10px; font-weight: bold;">Members of the Dar Es Salaam Stock Exchange</div>
                        <div style="color: #042D92; font-size: 8px;">House No. 11|Ursino Street|Mikocheni A| P.O Box 8706 - Dar es Salaam</div>
                        <div style="color: #042D92; font-size: 8px; font-weight: bold;">Mob: +255 752 824 977| Tel: +255 22 211 2691| Email: info@vfsl.co.tz</div>
                    </td>
                </tr>
            </table>
            <div style="border-top: 2px solid #042D92; border-bottom: 1px solid #FF0000; margin-top: 6px; height: 3px;"></div>
        </div>
        
        <div style="text-align: center; margin-bottom: 15px;">
            <p style="margin: 3px 0 0 0; font-size: 10px; color: #042D92; font-style: italic;">( Subject to the Rules and Practice of the Dar es Salaam Stock Exchange )</p>
        </div>
        
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 10px;">
            <div>
                <strong>TRADE DATE :</strong><?php echo date('d/m/Y', strtotime($trade['trade_date'])); ?><br>
                <strong>ORDER NO :</strong><?php echo $order_number; ?><br>
                <strong>SETTLEMENT DATE :</strong><?php echo date('d/m/Y', strtotime($trade['settlement_date'])); ?>
            </div>
            <div>
                <strong>CSD ACCOUNT NO :</strong><?php echo htmlspecialchars($trade['client_cds_account']); ?>
            </div>
        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px;">
            <div style="font-weight: bold; font-size: 12px;"><?php echo strtoupper(htmlspecialchars($trade['client_name'])); ?></div>
           Account No: <?php echo substr($trade['client_cds_account'], -6); ?>
        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px;">
            <div style="font-weight: bold;">CLIENT <?php echo strtoupper($trade['trade_side']); ?> CONTRACT NOTE <?php echo $is_summary ? '(SUMMARY) ' : ''; ?>No. : <?php echo $contract_number; ?></div>
            <div>Dar es Salaam Stock Exchange Transaction No. : <?php echo $exchange_ref; ?></div>
        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px; line-height: 1.4;">
            We wish to advise that in accordance with your instructions we have <strong><?php echo strtoupper($trade['trade_side']); ?></strong> on your account subject
            to the Rules, Regulations and Customs of the Dar es salaam Stock Exchange:-
        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px;">
            <div><strong>[DSE]</strong> <?php echo strtoupper(htmlspecialchars($trade['security_id'])); ?> (ISIN Code : <?php echo htmlspecialchars($trade['security_id']); ?>)</div>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px;">
            <thead>
                <tr style="background-color: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">QUANTITY</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">PRICE</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">CONSIDERATION</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo number_format($quantity, 0); ?></td>
                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo number_format($price, 6); ?></td>
                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo number_format($consideration, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <?php 
        $fees = calculateFees($price, $consideration, $trade['asset_class'], []);
        ?>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px;">
            <tbody>
                <tr>
                    <td style="padding: 3px 0;">Brokerage Commission</td>
                    <td style="padding: 3px 0;"><?php echo number_format($consideration, 2); ?>@ <?php echo number_format($fees['brokerage_commission'] / $consideration * 100, 4); ?>%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['brokerage_commission'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">VAT on Brokerage Commission</td>
                    <td style="padding: 3px 0;">@ 18.000%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['vat_on_brokerage'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">CMSA Transaction Fee</td>
                    <td style="padding: 3px 0;">@ <?php echo number_format($fees['cmsa_fee'] / $consideration * 100, 4); ?>%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['cmsa_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">DSE Transaction Fee(VAT INCL)</td>
                    <td style="padding: 3px 0;">@ <?php echo number_format($fees['dse_fee'] / $consideration * 100, 4); ?>%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['dse_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">Fidelity Fee</td>
                    <td style="padding: 3px 0;">@ <?php echo number_format($fees['fidelity_fee'] / $consideration * 100, 4); ?>%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['fidelity_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">CDS Fee(VAT INCL)</td>
                    <td style="padding: 3px 0;">@ <?php echo number_format($fees['cds_fee'] / $consideration * 100, 4); ?>%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['cds_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">Bank Charges</td>
                    <td style="padding: 3px 0;">Flat</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['bank_charges'], 2); ?></td>
                </tr>
                <tr style="border-top: 1px solid #ddd; font-weight: bold;">
                    <td style="padding: 8px 0;">Total Charges</td>
                    <td style="padding: 8px 0;"></td>
                    <td style="padding: 8px 0; text-align: right;"><?php echo number_format($total_charges, 2); ?></td>
                </tr>
                <tr style="font-weight: bold; font-size: 12px;">
                    <td style="padding: 8px 0;">TOTAL AMOUNT <?php echo $amount_label; ?></td>
                    <td style="padding: 8px 0;"></td>
                    <td style="padding: 8px 0; text-align: right;"><?php echo number_format($net_amount, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; font-size: 10px;">
            <div style="margin-bottom: 20px;">Yours Faithfully,</div>
            <div style="margin-bottom: 20px; font-weight: bold;">FOR NEOVAM LTD</div>
            
            <div style="display: flex; justify-content: space-between; margin-top: 40px;">
                <div style="width: 45%;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px;"></div>
                    <div style="text-align: center; font-weight: bold;">SIGNATURE OF CLIENT</div>
                </div>
                <div style="width: 45%;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px;"></div>
                    <div style="text-align: center; font-weight: bold;">STAMP & SIGNATURE OF LDM</div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #eee; padding-top: 15px;">
            <p style="margin: 0; line-height: 1.2;">
                NEOVAM LTD has prepared this Report solely for informational purposes. NEOVAM LTD does not represent warrant or guarantee that the
                Reports are accurate. NEOVAM LTD disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
                of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
            </p>
            <div style="text-align: right; margin-top: 5px; font-weight: bold;">Page 2 of 2</div>
        </div>
        
        <?php if ($watermark === 'yes'): ?>
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); 
                    font-size: 72px; color: rgba(0,0,0,0.1); font-weight: bold; z-index: -1; pointer-events: none;">
            VICTORY FINANCIAL SERVICES
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function generateBondContractNoteHTML($trade, $contract_number, $order_number, $exchange_ref, $quantity, $price, $consideration, $total_charges, $net_amount, $watermark, $is_summary = false) {
    global $db;
    
    // Get bond details from master data
    $bond_query = "SELECT * FROM bonds WHERE security_id = ? LIMIT 1";
    $bond_stmt = $db->prepare($bond_query);
    $bond_stmt->execute([$trade['security_id']]);
    $bond_details = $bond_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate Treasury bond specific fees
    $fees = calculateTreasuryBondFees($quantity, $consideration);
    
    // FIXED: Use consistent trade side detection
    $trade_side_upper = strtoupper(trim($trade['trade_side']));
    $amount_label = $trade_side_upper === 'SELL' ? 'RECEIVABLE' : 'PAYABLE';
    ?>
    <div class="contract-note" style="font-family: Arial, sans-serif; font-size: 11px; line-height: 1.3; max-width: 800px; margin: 0 auto; background: white; padding: 20px;">
        <div style="text-align: center; margin-bottom: 15px; font-size: 9px; color: #666;">
            <div style="text-align: right; margin-top: 5px; font-weight: bold;"></div>
        </div>

   <div style="text-align: center; margin-bottom: 20px; border-bottom: 1px solid #000; padding-bottom: 10px;">
            <img src="../assets/HeaderLogoVfsl.jpg" alt="Victory Financial Services" style="height: 80px; width: 500px; display: block; margin: 0 auto;">
        </div>
    
        
        <div style="text-align: center;">
            <p style="margin: 5px 0 0 0; font-size: 10px;">( Subject to the Rules and Practice of the Dar es salaam Stock Exchange )</p>
        </div>
        
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 10px;">
            <div>
                <strong>TRADE DATE :</strong><?php echo date('d/m/Y', strtotime($trade['trade_date'])); ?><br>
                <strong>ORDER NO :</strong><?php echo $order_number; ?><br>
                <strong>SETTLEMENT DATE :</strong><?php echo date('d/m/Y', strtotime($trade['settlement_date'])); ?>
            </div>
            <div>
                <strong>CSD ACCOUNT NO :</strong><?php echo htmlspecialchars($trade['client_cds_account']); ?>
            </div>
        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px;">
            <div style="font-weight: bold; font-size: 12px;"><?php echo strtoupper(htmlspecialchars($trade['client_name'])); ?></div>
Account: <?php echo substr($trade['client_cds_account'], -6); ?>        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px;">
            <div style="font-weight: bold;">CLIENT <?php echo strtoupper($trade['trade_side']); ?> CONTRACT NOTE <?php echo $is_summary ? '(SUMMARY) ' : ''; ?>No: <?php echo $contract_number; ?></div>
            <div>Dar es Salaam Stock Exchange Transaction No.: <?php echo $exchange_ref; ?></div>
        </div>
        
        <div style="margin-bottom: 15px; font-size: 10px; line-height: 1.4;">
            We wish to advise that in accordance with your instructions we have <strong><?php echo strtoupper($trade['trade_side']); ?></strong> on your account subject to the Rules,
            Regulations and Customs of the Dar es salaam Stock Exchange:-
        </div>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px;">
            <tbody>
                <tr>
                    <td style="padding: 3px 0; width: 30%;"><strong>ISSUE NUMBER</strong></td>
                    <td style="padding: 3px 0;"><?php echo htmlspecialchars($trade['security_id']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>ISIN NO</strong></td>
                    <td style="padding: 3px 0;"><?php echo htmlspecialchars($bond_details['isin'] ?? 'TZ1996103689'); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>BOND NUMBER</strong></td>
                    <td style="padding: 3px 0;"><?php echo htmlspecialchars($bond_details['bond_number'] ?? substr($trade['security_id'], 0, 3)); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Issue Date</strong></td>
                    <td style="padding: 3px 0;"><?php echo date('d/m/Y', strtotime($bond_details['issue_date'] ?? $trade['trade_date'])); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Maturity Date</strong></td>
                    <td style="padding: 3px 0;"><?php echo date('d/m/Y', strtotime($bond_details['maturity_date'] ?? '+5 years', strtotime($trade['trade_date']))); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Coupon(%)</strong></td>
                    <td style="padding: 3px 0;"><?php echo number_format($bond_details['coupon_rate'] ?? 15.49, 4); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Value Date</strong></td>
                    <td style="padding: 3px 0;"><?php echo date('d/m/Y', strtotime($trade['settlement_date'])); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Amount(TZs)</strong></td>
                    <td style="padding: 3px 0;"><?php echo number_format($quantity, 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Price(%)</strong></td>
                    <td style="padding: 3px 0;"><?php echo number_format($price, 4); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;"><strong>Consideration(TZs)</strong></td>
                    <td style="padding: 3px 0;"><?php echo number_format($consideration, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px;">
            <tbody>
                <tr>
                    <td style="padding: 3px 0;">Brokerage Commission</td>
                    <td style="padding: 3px 0;"><?php echo number_format($quantity, 2); ?> @ 0.02500%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['brokerage_commission'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">VAT on Brokerage Commission</td>
                    <td style="padding: 3px 0;">@ 18.0000%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['vat_on_brokerage'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">CMSA Transaction Fee</td>
                    <td style="padding: 3px 0;"><?php echo number_format($consideration, 2); ?> @ 0.0100%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['cmsa_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">CSDR Fee (VAT included)</td>
                    <td style="padding: 3px 0;"><?php echo number_format($quantity, 2); ?> @ 0.011800%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['csdr_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">DSE Transaction Fee</td>
                    <td style="padding: 3px 0;"><?php echo number_format($quantity, 2); ?> @ 0.0200600%</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['dse_fee'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding: 3px 0;">Bank Charges</td>
                    <td style="padding: 3px 0;">Flat</td>
                    <td style="padding: 3px 0; text-align: right;"><?php echo number_format($fees['bank_charges'], 2); ?></td>
                </tr>
                <tr style="border-top: 1px solid #ddd; font-weight: bold;">
                    <td style="padding: 8px 0;">Total Charges</td>
                    <td style="padding: 8px 0;"></td>
                    <td style="padding: 8px 0; text-align: right;"><?php echo number_format($fees['total_charges'], 2); ?></td>
                </tr>
                <tr style="font-weight: bold; font-size: 12px;">
                    <td style="padding: 8px 0;">TOTAL AMOUNT <?php echo $amount_label; ?></td>
                    <td style="padding: 8px 0;"></td>
                    <td style="padding: 8px 0; text-align: right;"><?php echo number_format($net_amount, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; font-size: 10px;">
            <div style="margin-bottom: 20px;">Yours Faithfully,</div>
            <div style="margin-bottom: 20px; font-weight: bold;">FOR NEOVAM LTD</div>
            
            <div style="display: flex; justify-content: space-between; margin-top: 40px;">
                <div style="width: 45%;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px;"></div>
                    <div style="text-align: center; font-weight: bold;">SIGNATURE OF CLIENT</div>
                </div>
                <div style="width: 45%;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 5px; height: 30px;"></div>
                    <div style="text-align: center; font-weight: bold;">STAMP & SIGNATURE OF LDM</div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #eee; padding-top: 15px;">
            <p style="margin: 0; line-height: 1.2;">
                NEOVAM LTD has prepared this Report solely for informational purposes. NEOVAM LTD does not represent warrant or guarantee that the
                Reports are accurate. NEOVAM LTD disclaims liability for any direct indirect punitive special consequential or incidental damages related to the Reports or the use
                of the Reports. This disclaimer applies to the Reports in their entirety irrespective of whether the Reports are used or viewed in whole or in part.
            </p>
            <div style="text-align: right; margin-top: 5px; font-weight: bold;">Page 2 of 2</div>
        </div>
        
        <?php if ($watermark === 'yes'): ?>
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); 
                    font-size: 72px; color: rgba(0,0,0,0.1); font-weight: bold; z-index: -1; pointer-events: none;">
            VICTORY FINANCIAL SERVICES
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function generateTransactionSummaryReports($trades, $report_type, $report_by, $master_data) {
    if (empty($trades)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No trades found</h5>
                <p class="text-muted">No trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }

    $grouped_trades = [];
    $total_summary = [
        'total_quantity' => 0,
        'total_consideration' => 0,
        'total_brokerage' => 0,
        'total_vat' => 0,
        'total_cmsa' => 0,
        'total_dse' => 0,
        'total_cds' => 0,
        'total_fidelity' => 0,
        'total_csdr' => 0
    ];

    foreach ($trades as $trade) {
        $group_key = '';
        if ($report_by === 'Month') {
            $group_key = date('Y-m', strtotime($trade['trade_date']));
        } elseif ($report_by === 'Client') {
            $group_key = $trade['client_cds_account'];
        } elseif ($report_by === 'Security') {
            $group_key = $trade['security_id'];
        } elseif ($report_by === 'Asset Class') {
            $group_key = $trade['asset_class'];
        } else {
            $group_key = 'All Trades';
        }

        if (!isset($grouped_trades[$group_key])) {
            $grouped_trades[$group_key] = [
                'trades' => [],
                'summary' => [
                    'total_quantity' => 0,
                    'total_consideration' => 0,
                    'total_brokerage' => 0,
                    'total_vat' => 0,
                    'total_cmsa' => 0,
                    'total_dse' => 0,
                    'total_cds' => 0,
                    'total_fidelity' => 0,
                    'total_csdr' => 0
                ],
            ];
        }

        // Use appropriate fee calculation based on asset class
        if ($trade['asset_class'] === 'bond') {
            $fees = calculateTreasuryBondFees(floatval($trade['quantity']), floatval($trade['consideration']));
        } else {
            $fees = calculateFees(floatval($trade['price']), floatval($trade['consideration']), $trade['asset_class'], $master_data);
        }
        
        $grouped_trades[$group_key]['trades'][] = $trade;
        $grouped_trades[$group_key]['summary']['total_quantity'] += floatval($trade['quantity']);
        $grouped_trades[$group_key]['summary']['total_consideration'] += floatval($trade['consideration']);
        $grouped_trades[$group_key]['summary']['total_brokerage'] += $fees['brokerage_commission'];
        $grouped_trades[$group_key]['summary']['total_vat'] += $fees['vat_on_brokerage'];
        $grouped_trades[$group_key]['summary']['total_cmsa'] += $fees['cmsa_fee'];
        $grouped_trades[$group_key]['summary']['total_dse'] += $fees['dse_fee'];
        $grouped_trades[$group_key]['summary']['total_cds'] += $fees['cds_fee'];
        $grouped_trades[$group_key]['summary']['total_fidelity'] += $fees['fidelity_fee'];
        $grouped_trades[$group_key]['summary']['total_csdr'] += $fees['csdr_fee'];
        $grouped_trades[$group_key]['summary']['total_bank_charges'] = ($grouped_trades[$group_key]['summary']['total_bank_charges'] ?? 0) + ($fees['bank_charges'] ?? 0);

        $total_summary['total_quantity'] += floatval($trade['quantity']);
        $total_summary['total_consideration'] += floatval($trade['consideration']);
        $total_summary['total_brokerage'] += $fees['brokerage_commission'];
        $total_summary['total_vat'] += $fees['vat_on_brokerage'];
        $total_summary['total_cmsa'] += $fees['cmsa_fee'];
        $total_summary['total_dse'] += $fees['dse_fee'];
        $total_summary['total_cds'] += $fees['cds_fee'];
        $total_summary['total_fidelity'] += $fees['fidelity_fee'];
        $total_summary['total_csdr'] += $fees['csdr_fee'];
        $total_summary['total_bank_charges'] = ($total_summary['total_bank_charges'] ?? 0) + ($fees['bank_charges'] ?? 0);
    }

    generateSummaryReportHTML($grouped_trades, $report_type, $report_by, $master_data, $total_summary);
}

function generateSummaryReportHTML($grouped_trades, $report_type, $report_by, $master_data, $total_summary) {
    ?>
    <style>
        .report-table th, .report-table td {
            border: 1px solid #ddd;
            padding: 8px;
            font-size: 10px;
        }
        .report-table th {
            background-color: #f2f2f2;
            text-align: left;
        }
    </style>
    <div style="font-family: Arial, sans-serif; padding: 20px;">
        <h2 style="text-align: center;">Transaction Summary Report</h2>
        <h4 style="text-align: center;">Grouped by: <?php echo htmlspecialchars($report_by); ?></h4>
        <div style="margin-bottom: 20px; font-size: 10px;">
            <strong>Report Date:</strong> <?php echo date('d/m/Y H:i:s'); ?>
        </div>
        
        <?php foreach ($grouped_trades as $group_key => $group_data): ?>
            <h5 style="margin-top: 20px;">Group: <?php echo htmlspecialchars($group_key); ?></h5>
            <table class="report-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th>Trade Date</th>
                        <th>Client Name</th>
                        <th>Security</th>
                        <th>Asset Class</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Consideration</th>
                        <th>Brokerage</th>
                        <th>VAT</th>
                        <th>Bank Charges</th>
                        <th>Total Charges</th>
                        <th>Net Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group_data['trades'] as $trade): 
                        $consideration = floatval($trade['consideration']);
                        $price = floatval($trade['price']);
                        
                        // Use appropriate fee calculation
                        if ($trade['asset_class'] === 'bond') {
                            $fees = calculateTreasuryBondFees(floatval($trade['quantity']), $consideration);
                        } else {
                            $fees = calculateFees($price, $consideration, $trade['asset_class'], $master_data);
                        }
                        
                        $total_charges = $fees['total_charges'];
                        
                        // CORRECTED: For SELL trades, deduct charges; for BUY trades, add charges
                        $net_amount = $trade['trade_side'] === 'Sell' ? 
                            $consideration - $total_charges : 
                            $consideration + $total_charges;
                    ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($trade['trade_date'])); ?></td>
                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                            <td><?php echo htmlspecialchars($trade['security_id']); ?></td>
                            <td><?php echo htmlspecialchars($trade['asset_class']); ?></td>
                            <td><?php echo htmlspecialchars($trade['trade_side']); ?></td>
                            <td><?php echo number_format($trade['quantity'], 0); ?></td>
                            <td><?php echo number_format($price, 4); ?></td>
                            <td><?php echo number_format($consideration, 2); ?></td>
                            <td><?php echo number_format($fees['brokerage_commission'], 2); ?></td>
                            <td><?php echo number_format($fees['vat_on_brokerage'], 2); ?></td>
                            <td><?php echo number_format($fees['bank_charges'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($total_charges, 2); ?></td>
                            <td><?php echo number_format($net_amount, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold; background-color: #e9ecef;">
                        <td colspan="5" style="text-align: right;">Group Total:</td>
                        <td><?php echo number_format($group_data['summary']['total_quantity'], 0); ?></td>
                        <td></td>
                        <td><?php echo number_format($group_data['summary']['total_consideration'], 2); ?></td>
                        <td><?php echo number_format($group_data['summary']['total_brokerage'], 2); ?></td>
                        <td><?php echo number_format($group_data['summary']['total_vat'], 2); ?></td>
                        <td><?php echo number_format($group_data['summary']['total_bank_charges'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($group_data['summary']['total_brokerage'] + $group_data['summary']['total_vat'] + $group_data['summary']['total_cmsa'] + $group_data['summary']['total_dse'] + $group_data['summary']['total_cds'] + $group_data['summary']['total_fidelity'] + $group_data['summary']['total_csdr'] + ($group_data['summary']['total_bank_charges'] ?? 0), 2); ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        <?php endforeach; ?>
        
        <div style="margin-top: 30px; font-weight: bold; font-size: 12px; border-top: 2px solid #000; padding-top: 10px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 20%;">GRAND TOTAL:</td>
                    <td style="width: 10%; text-align: right;"><?php echo number_format($total_summary['total_quantity'], 0); ?></td>
                    <td style="width: 10%;"></td>
                    <td style="width: 10%; text-align: right;"><?php echo number_format($total_summary['total_consideration'], 2); ?></td>
                    <td style="width: 10%; text-align: right;"><?php echo number_format($total_summary['total_brokerage'], 2); ?></td>
                    <td style="width: 10%; text-align: right;"><?php echo number_format($total_summary['total_vat'], 2); ?></td>
                    <td style="width: 10%; text-align: right;"><?php echo number_format($total_summary['total_bank_charges'] ?? 0, 2); ?></td>
                    <td style="width: 10%; text-align: right;"><?php echo number_format($total_summary['total_brokerage'] + $total_summary['total_vat'] + $total_summary['total_cmsa'] + $total_summary['total_dse'] + $total_summary['total_cds'] + $total_summary['total_fidelity'] + $total_summary['total_csdr'] + ($total_summary['total_bank_charges'] ?? 0), 2); ?></td>
                    <td style="width: 10%;"></td>
                </tr>
            </table>
        </div>
    </div>
    <?php
}

function generateBrokerCustodianReports($trades, $report_type, $report_by, $master_data) {
    echo '<div class="alert alert-warning text-center py-5">
            <i class="bi bi-exclamation-triangle fs-1 text-muted mb-3"></i>
            <h5>Broker & Custodian Reports are not yet implemented</h5>
            <p class="text-muted">This feature is under development. Please check back later.</p>
          </div>';
    return;
}

function generateSummaryReports($trades, $report_type, $report_by, $master_data) {
    generateTransactionSummaryReports($trades, $report_type, $report_by, $master_data);
}

function generateLedgerEntries($trades, $report_type, $report_by, $master_data) {
    if (empty($trades)) {
        echo '<div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle fs-1 text-muted mb-3"></i>
                <h5>No trades found</h5>
                <p class="text-muted">No trades match your selected criteria. Please adjust your filters and try again.</p>
              </div>';
        return;
    }
    
    foreach ($trades as $trade) {
        generateSingleLedgerEntry($trade, $master_data);
        if (count($trades) > 1) {
            echo '<div style="page-break-after: always;"></div>';
        }
    }
}

function generateSingleLedgerEntry($trade, $master_data) {
    // This is a placeholder for the single ledger entry function.
    // The code for this function was not provided in the prompt.
    // You would need to add your own implementation here.
    echo '<div style="font-family: Arial, sans-serif; padding: 20px;">';
    echo '<h3>Ledger Entry - Under Development</h3>';
    echo '<p>Trade ID: ' . $trade['id'] . '</p>';
    echo '<p>Client: ' . htmlspecialchars($trade['client_name']) . '</p>';
    echo '<p>Security: ' . htmlspecialchars($trade['security_id']) . '</p>';
    echo '<p>Asset Class: ' . htmlspecialchars($trade['asset_class']) . '</p>';
    echo '<p>Type: ' . htmlspecialchars($trade['trade_side']) . '</p>';
    echo '</div>';
}
?>