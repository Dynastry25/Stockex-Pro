<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';
require_once '../tcpdf/tcpdf.php';

require_login();

$allowed_roles = ['finance_officer', 'system_admin', 'trader'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    die('Access denied.');
}

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();

$company_stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$company = $company_stmt->fetch();
$company_name = $company ? $company['company_name'] : 'Neovam LTD';
$company_email = $company ? $company['email'] : 'info@neovam.com';

// Load trades.php functions/classes via output buffering to suppress HTML
ob_start();
$standard_rate_percentage = 1.5;
require_once '../trader/trades.php';
ob_end_clean();

// Calculate bank charge based on consideration
function calculateBankCharge($consideration) {
    if ($consideration < 100000) {
        return 0;
    } elseif ($consideration < 10000000) {
        return 2000;
    } elseif ($consideration < 50000000) {
        return 6000;
    } else {
        return 12000;
    }
}

// Must be a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settlement.php?message=' . urlencode('Please use the Export button on the settlement page.') . '&type=warning');
    exit;
}

// Get parameters
$export_type = $_POST['export_type'] ?? '';
$export_date = $_POST['export_date'] ?? date('Y-m-d');
$cds_filter = trim($_POST['cds_filter'] ?? '');

if (!in_array($export_type, ['contract_notes', 'client_list'])) {
    header('Location: settlement.php?message=' . urlencode('Invalid export type selected.') . '&type=danger');
    exit;
}

// Fetch trades for the selected settlement date
$query = "
    SELECT t.*, 
           cl.address as client_address,
           cl.email as client_email,
           cl.fee_type as client_fee_type,
           cl.default_brokerage_fee,
           cl.liberty_mode as client_liberty_mode
    FROM trades t
    LEFT JOIN clients cl ON t.client_name = cl.client_name
    WHERE t.settlement_date IS NOT NULL 
    AND t.settlement_date = ?
    AND t.status = 'active'
    AND (t.settlement_status IS NULL OR t.settlement_status NOT IN ('cancelled'))
";

$params = [$export_date];

if (!empty($cds_filter)) {
    $query .= " AND t.client_cds_account = ?";
    $params[] = $cds_filter;
}

$query .= " ORDER BY t.client_cds_account, t.trade_side, t.security_id, t.created_at";

$stmt = $db->prepare($query);
$stmt->execute($params);
$trades = $stmt->fetchAll();

if (empty($trades)) {
    header('Location: settlement.php?message=' . urlencode('No trades found for the selected date.' . (!empty($cds_filter) ? ' CDS: ' . $cds_filter : '')) . '&type=warning');
    exit;
}

// Group trades by CDS account
$grouped = [];
foreach ($trades as $trade) {
    $cds = $trade['client_cds_account'];
    if (!isset($grouped[$cds])) {
        $grouped[$cds] = [
            'client_name' => $trade['client_name'],
            'client_address' => $trade['client_address'] ?? '',
            'client_email' => $trade['client_email'] ?? '',
            'trades' => []
        ];
    }
    $grouped[$cds]['trades'][] = $trade;
}

// ============================================================
// CLIENT LIST EXPORT
// ============================================================
if ($export_type === 'client_list') {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company_name);
    $pdf->SetTitle('Settlement Client List - ' . $export_date);
    $pdf->SetSubject('Settlement Due Clients');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, strtoupper($company_name), 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'SETTLEMENT CLIENT LIST', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Settlement Date: ' . date('d/m/Y', strtotime($export_date)), 0, 1, 'C');
    $pdf->Ln(5);

    // Summary
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Total Clients: ' . count($grouped) . ' | Total Trades: ' . count($trades), 0, 1, 'L');
    $pdf->Ln(3);

    // Table header
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(230, 230, 230);
    $headers = ['#', 'CDS Account', 'Client Name', 'Side', 'Security', 'Quantity', 'Price', 'Consideration (TZS)', 'Status'];
    $colWidths = [8, 22, 45, 10, 28, 18, 20, 28, 16];
    $totalWidth = array_sum($colWidths);

    foreach ($headers as $i => $h) {
        $pdf->Cell($colWidths[$i], 7, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Table rows
    $pdf->SetFont('helvetica', '', 7);
    $idx = 1;
    $grand_total = 0;

    foreach ($grouped as $cds => $group) {
        $client_drawn = false;
        foreach ($group['trades'] as $trade) {
            $status = ucfirst($trade['settlement_status'] ?? 'Pending');
            $side = strtoupper($trade['trade_side']);
            $consideration = floatval($trade['consideration']);
            $grand_total += $side === 'SELL' ? $consideration : 0;

            if (!$client_drawn) {
                // Client sub-header
                $pdf->SetFont('helvetica', 'B', 7);
                $pdf->SetFillColor(245, 245, 250);
                $pdf->Cell($totalWidth, 6, 'Client: ' . $group['client_name'] . ' (CDS: ' . $cds . ')', 1, 1, 'L', true);
                $pdf->SetFont('helvetica', '', 7);
                $client_drawn = true;
            }

            $pdf->Cell($colWidths[0], 5, $idx++, 1, 0, 'C');
            $pdf->Cell($colWidths[1], 5, $cds, 1, 0, 'C');
            $pdf->Cell($colWidths[2], 5, $trade['client_name'], 1, 0, 'L');
            $pdf->Cell($colWidths[3], 5, $side, 1, 0, 'C');
            $pdf->Cell($colWidths[4], 5, $trade['security_id'], 1, 0, 'L');
            $pdf->Cell($colWidths[5], 5, number_format($trade['quantity'], 0), 1, 0, 'R');
            $pdf->Cell($colWidths[6], 5, number_format($trade['price'], 4), 1, 0, 'R');
            $pdf->Cell($colWidths[7], 5, number_format($consideration, 2), 1, 0, 'R');
            $pdf->Cell($colWidths[8], 5, $status, 1, 1, 'C');

            if ($pdf->GetY() > 255) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetFillColor(230, 230, 230);
                foreach ($headers as $i => $h) {
                    $pdf->Cell($colWidths[$i], 7, $h, 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 7);
            }
        }
    }

    // Totals
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(80, 6, 'Total Trades: ' . count($trades), 0, 0, 'L');
    $pdf->Cell(0, 6, 'Total Clients: ' . count($grouped), 0, 1, 'L');

    $filename = 'settlement_client_list_' . date('Ymd', strtotime($export_date)) . '.pdf';
    $pdf->Output($filename, 'I');
    exit;
}

// ============================================================
// CONTRACT NOTES EXPORT (Bulk)
// ============================================================
$pdf = new ContractNotePDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($company_name);
$pdf->SetTitle('Settlement Contract Notes - ' . $export_date);
$pdf->SetSubject('Bulk Settlement Contract Notes');
$pdf->setWatermarkEnabled(true);
$pdf->setCompanyName($company_name);
$pdf->SetMargins(25.4, 25, 25.4);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 12);

$total_trades_overall = count($trades);
$current_trade_index = 1;

foreach ($grouped as $cds => $group) {
    $group_trades = $group['trades'];
    $client_name = $group['client_name'];
    $trade_date = $group_trades[0]['trade_date'];

    // Determine grouping: same side + same security = summary, otherwise individual
    $subgroups = [];
    foreach ($group_trades as $t) {
        $key = $t['trade_side'] . '|' . $t['security_id'];
        if (!isset($subgroups[$key])) {
            $subgroups[$key] = [];
        }
        $subgroups[$key][] = $t;
    }

    foreach ($subgroups as $key => $subgroup) {
        list($trade_side, $security_id) = explode('|', $key);

        if (count($subgroup) > 1) {
            // Generate summary contract note for multiple trades
            $total_quantity = 0;
            $total_consideration = 0;
            $total_fees = 0;

            $first_trade = $subgroup[0];
            $asset_class = $first_trade['asset_class'];

            $client = [
                'fee_type' => $first_trade['client_fee_type'] ?? 'default',
                'default_brokerage_fee' => $first_trade['default_brokerage_fee'] ?? 0,
                'liberty_mode' => $first_trade['client_liberty_mode'] ?? 'replace_all'
            ];
            $effective_rate = getEffectiveBrokerageRate($db, $first_trade, $client);

            $liberty_mode = $first_trade['liberty_mode'] ?? ($client['liberty_mode'] ?? 'replace_all');
            $is_liberty = ($first_trade['brokerage_fee_type'] === 'liberty' || $first_trade['brokerage_fee_type'] === 'this_trade');

            foreach ($subgroup as $t) {
                $total_quantity += floatval($t['quantity']);
                $total_consideration += floatval($t['consideration']);
                $fees = calculateFeesWithEffectiveRate($db, $t['asset_class'],
                    floatval($t['consideration']), floatval($t['quantity']), floatval($t['price']),
                    $effective_rate, $liberty_mode, $is_liberty);
                $total_fees += $fees['total'];
            }

            $bank_charge = calculateBankCharge($total_consideration);

            $average_price = $total_quantity > 0 ? $total_consideration / $total_quantity : 0;

            $summary_trade = $subgroup[0];
            $summary_trade['quantity'] = $total_quantity;
            $summary_trade['price'] = $average_price;
            $summary_trade['consideration'] = $total_consideration;
            $summary_trade['brokerage_fee_type'] = $first_trade['brokerage_fee_type'];
            $summary_trade['custom_brokerage_fee'] = $first_trade['custom_brokerage_fee'];
            $summary_trade['liberty_mode'] = $liberty_mode;
            $summary_trade['effective_rate_for_display'] = $effective_rate;

            $summary_fees = calculateFeesWithEffectiveRate($db, $asset_class,
                $total_consideration, $total_quantity, $average_price,
                $effective_rate, $liberty_mode, $is_liberty);
            $summary_fees['bank_charge'] = $bank_charge;
            $summary_fees['total'] += $bank_charge;

            $trade_side_upper = strtoupper(trim($trade_side));
            $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($subgroup[0]['id'], 6, '0', STR_PAD_LEFT) . '-SUM';
            $order_number = 'SUMMARY-' . date('ymd', strtotime($trade_date));
            $exchange_ref = date('ymd', strtotime($trade_date)) . 'SUM';

            $summary_data = [
                'total_quantity' => $total_quantity,
                'average_price' => $average_price,
                'trade_count' => count($subgroup),
                'total_consideration' => $total_consideration,
                'total_fees' => $total_fees
            ];

            $pdf->setCurrentTrade($current_trade_index);
            $pdf->addContractNote($summary_trade, $summary_fees, $contract_number, $order_number, $exchange_ref, true, $summary_data);
            $pdf->addSummaryBreakdown($subgroup, $summary_data, $client_name, $trade_date, $security_id, $trade_side);
            $current_trade_index++;
        } else {
            // Single trade - normal contract note
            $trade = $subgroup[0];

            $client = [
                'fee_type' => $trade['client_fee_type'] ?? 'default',
                'default_brokerage_fee' => $trade['default_brokerage_fee'] ?? 0,
                'liberty_mode' => $trade['client_liberty_mode'] ?? 'replace_all'
            ];
            $effective_rate = getEffectiveBrokerageRate($db, $trade, $client);

            $liberty_mode = $trade['liberty_mode'] ?? ($client['liberty_mode'] ?? 'replace_all');
            $is_liberty = ($trade['brokerage_fee_type'] === 'liberty' || $trade['brokerage_fee_type'] === 'this_trade');

            $fees = calculateFeesWithEffectiveRate($db, $trade['asset_class'],
                floatval($trade['consideration']), floatval($trade['quantity']), floatval($trade['price']),
                $effective_rate, $liberty_mode, $is_liberty);

            $bank_charge = calculateBankCharge(floatval($trade['consideration']));
            $fees['bank_charge'] = $bank_charge;
            $fees['total'] += $bank_charge;

            $trade_side_upper = strtoupper(trim($trade['trade_side']));
            $contract_number = ($trade_side_upper === 'SELL' ? 'S' : 'P') . str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
            $order_number = str_pad($trade['id'], 6, '0', STR_PAD_LEFT);
            $exchange_ref = date('ymd', strtotime($trade['trade_date'])) . str_pad($trade['id'], 3, '0', STR_PAD_LEFT);

            $trade['liberty_mode'] = $liberty_mode;
            $trade['effective_rate_for_display'] = $effective_rate;

            $pdf->setCurrentTrade($current_trade_index);
            $pdf->addContractNote($trade, $fees, $contract_number, $order_number, $exchange_ref);
            $current_trade_index++;
        }
    }
}

$pdf->setTotalTrades($current_trade_index - 1);

$filename = 'settlement_contract_notes_' . date('Ymd', strtotime($export_date)) . '.pdf';
$pdf->Output($filename, 'I');
