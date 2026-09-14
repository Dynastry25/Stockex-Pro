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
        return 250;
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

// Get the current settlement page filter state so the export matches the
// active tab (Pending / Overdue / Due Today / Paid / Linked / Failed /
// All Settlements) and the applied client/security/date/amount filters.
$filter_tab = $_POST['tab'] ?? 'all';
$filter_status = trim($_POST['filter_status'] ?? '');
if (empty($filter_status) && in_array($filter_tab, ['linked', 'paid', 'failed', 'today', 'overdue', 'pending'])) {
    $filter_status = $filter_tab;
}
$filter_client = trim($_POST['filter_client'] ?? '');
$filter_security = trim($_POST['filter_security'] ?? '');
$filter_date_from = trim($_POST['filter_date_from'] ?? '');
$filter_date_to = trim($_POST['filter_date_to'] ?? '');
$filter_amount_min = (float)($_POST['filter_amount_min'] ?? 0);
$filter_amount_max = (float)($_POST['filter_amount_max'] ?? 0);
$today = date('Y-m-d');

$tab_active = in_array($filter_status, ['pending', 'overdue', 'today', 'paid', 'linked', 'failed']);
$has_date_filters = !empty($filter_date_from) || !empty($filter_date_to);

// Rebuild the settlement page URL with the current tab/filters so redirects
// (e.g. "no trades found") return to the same view the user was on.
function settlement_return_url_from_post() {
    $keep = ['tab', 'filter_status', 'filter_client', 'filter_security', 'filter_side',
             'filter_date_from', 'filter_date_to', 'filter_amount_min', 'filter_amount_max',
             'page', 'side', 'hide_buy'];
    $p = [];
    foreach ($keep as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') {
            $p[$k] = $_POST[$k];
        }
    }
    return 'settlement.php' . (!empty($p) ? '?' . http_build_query($p) : '');
}

if (!in_array($export_type, ['contract_notes', 'client_list'])) {
    $url = settlement_return_url_from_post();
    header('Location: ' . $url . (strpos($url, '?') !== false ? '&' : '?') . 'message=' . urlencode('Invalid export type selected.') . '&type=danger');
    exit;
}

// Fetch trades matching the current filtered settlement view.
// When a status tab or a date-range filter is active we match the on-screen
// window (same defaults the settlement page uses); otherwise we keep the
// classic single settlement-date export.
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
    AND t.status = 'active'
    AND t.trade_side = 'sell'
    AND (t.settlement_status IS NULL OR t.settlement_status NOT IN ('cancelled'))
";

$params = [];

if ($tab_active || $has_date_filters) {
    $d_from = !empty($filter_date_from) ? $filter_date_from : date('Y-m-d', strtotime('-30 days'));
    $d_to = !empty($filter_date_to) ? $filter_date_to : date('Y-m-d', strtotime('+30 days'));
    if ($d_from > $d_to) { $tmp = $d_from; $d_from = $d_to; $d_to = $tmp; }
    $query .= " AND t.settlement_date BETWEEN ? AND ?";
    $params[] = $d_from;
    $params[] = $d_to;
} else {
    $query .= " AND t.settlement_date = ?";
    $params[] = $export_date;
}

if (!empty($cds_filter)) {
    $query .= " AND t.client_cds_account = ?";
    $params[] = $cds_filter;
}

$query .= " ORDER BY t.client_cds_account, t.trade_side, t.security_id, t.created_at";

$stmt = $db->prepare($query);
$stmt->execute($params);
$trades = $stmt->fetchAll();

// Apply the same client/security/status/amount filters as the settlement page
// so the export matches the active tab and filters.
$trades = array_values(array_filter($trades, function ($trade) use ($filter_status, $filter_client, $filter_security, $filter_amount_min, $filter_amount_max, $today) {
    // Client filter
    if ($filter_client !== '' && stripos($trade['client_name'], $filter_client) === false) {
        return false;
    }
    
    // Security filter
    if ($filter_security !== '' && stripos($trade['security_id'], $filter_security) === false) {
        return false;
    }
    
    // Status / tab filter
    if (!empty($filter_status) && $filter_status !== 'all') {
        $status = $trade['settlement_status'] ?? 'pending';
        if ($filter_status === 'overdue') {
            if ($status === 'paid' || $status === 'linked' || $status === 'failed') {
                return false;
            }
            $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
            if ($settlement_date >= $today) {
                return false;
            }
        } elseif ($filter_status === 'today') {
            if ($status === 'paid' || $status === 'linked' || $status === 'failed') {
                return false;
            }
            $settlement_date = $trade['settlement_date'] ?? $trade['trade_date'];
            if ($settlement_date != $today) {
                return false;
            }
        } elseif ($filter_status === 'pending') {
            if ($status === 'paid' || $status === 'linked' || $status === 'failed') {
                return false;
            }
        } else {
            if ($status !== $filter_status) {
                return false;
            }
        }
    }
    
    // Amount range filter
    if ($filter_amount_min > 0 && floatval($trade['consideration']) < $filter_amount_min) {
        return false;
    }
    if ($filter_amount_max > 0 && floatval($trade['consideration']) > $filter_amount_max) {
        return false;
    }
    
    return true;
}));

if (empty($trades)) {
    $url = settlement_return_url_from_post();
    header('Location: ' . $url . (strpos($url, '?') !== false ? '&' : '?') . 'message=' . urlencode('No trades found for the selected date.' . (!empty($cds_filter) ? ' CDS: ' . $cds_filter : '')) . '&type=warning');
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
    $pdf->SetMargins(40, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    renderVfslPdfHeader($pdf, $company_name);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'SETTLEMENT CLIENT LIST', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Settlement Date: ' . date('d/m/Y', strtotime($export_date)), 0, 1, 'C');
    $pdf->Cell(0, 5, 'Total Clients: ' . count($grouped) . ' | Total Trades: ' . count($trades), 0, 1, 'C');
    $pdf->Ln(3);

    $colSide = 8; $colSecurity = 48; $colQty = 18; $colPrice = 22; $colConsider = 34;
    $tradeWidth = $colSide + $colSecurity + $colQty + $colPrice + $colConsider;
    $labelW = 55; $valueW = 35;
    $summaryLeft = $tradeWidth - $labelW - $valueW;

    // Grand totals
    $grand_consider = 0; $grand_brokerage = 0; $grand_bank = 0; $grand_totfees = 0; $grand_net = 0;

    foreach ($grouped as $cds => $group) {
        $group_trades = $group['trades'];
        $client_name = $group['client_name'];

        // Subgroup by side + security
        $subgroups = [];
        foreach ($group_trades as $t) {
            $key = $t['trade_side'] . '|' . $t['security_id'];
            $subgroups[$key][] = $t;
        }

        // Total consideration for ALL client trades
        $client_total_consider = 0;
        $client_total_qty = 0;
        foreach ($group_trades as $t) {
            $client_total_consider += floatval($t['consideration']);
            $client_total_qty += floatval($t['quantity']);
        }

        // Calculate fees ONCE on total consideration
        $first_trade = $group_trades[0];
        $asset_class = $first_trade['asset_class'];
        $client_fee = [
            'fee_type' => $first_trade['client_fee_type'] ?? 'default',
            'default_brokerage_fee' => $first_trade['default_brokerage_fee'] ?? 0,
            'liberty_mode' => $first_trade['client_liberty_mode'] ?? 'replace_all'
        ];
        $effective_rate = getEffectiveBrokerageRate($db, $first_trade, $client_fee);
        $liberty_mode = $first_trade['liberty_mode'] ?? ($client_fee['liberty_mode'] ?? 'replace_all');
        $is_liberty = ($first_trade['brokerage_fee_type'] === 'liberty' || $first_trade['brokerage_fee_type'] === 'this_trade');

        $avg_price = $client_total_qty > 0 ? $client_total_consider / $client_total_qty : 0;

        $client_fees = calculateFeesWithEffectiveRate($db, $asset_class,
            $client_total_consider, $client_total_qty, $avg_price,
            $effective_rate, $liberty_mode, $is_liberty);

        $client_bank = calculateBankCharge($client_total_consider);
        $client_fees['bank_charge'] = $client_bank;
        $client_fees['total'] += $client_bank;

        $client_brokerage = $client_fees['brokerage'];
        $client_totfees = $client_fees['total'];
        $client_net = $client_total_consider - $client_totfees;

        $grand_consider += $client_total_consider;
        $grand_brokerage += $client_brokerage;
        $grand_bank += $client_bank;
        $grand_totfees += $client_totfees;
        $grand_net += $client_net;

        // Page break check
        if ($pdf->GetY() > 210) {
            $pdf->AddPage();
        }

        // Client header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 245);
        $pdf->Cell($tradeWidth, 7, 'Client: ' . $client_name . '  |  CDS: ' . $cds . '  |  Trades: ' . count($group_trades), 1, 1, 'L', true);

        // Trade column headers
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($colSide, 6, 'Side', 1, 0, 'C', true);
        $pdf->Cell($colSecurity, 6, 'Security', 1, 0, 'C', true);
        $pdf->Cell($colQty, 6, 'Qty', 1, 0, 'C', true);
        $pdf->Cell($colPrice, 6, 'Price', 1, 0, 'C', true);
        $pdf->Cell($colConsider, 6, 'Consideration', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 7);

        foreach ($subgroups as $key => $subgroup) {
            list($trade_side, $security_id) = explode('|', $key);

            if (count($subgroup) > 1) {
                $qty = 0; $consider = 0;
                foreach ($subgroup as $t) {
                    $qty += floatval($t['quantity']);
                    $consider += floatval($t['consideration']);
                }
                $price = $qty > 0 ? $consider / $qty : 0;
                $sec_label = $security_id . ' *';
            } else {
                $t = $subgroup[0];
                $qty = floatval($t['quantity']);
                $price = floatval($t['price']);
                $consider = floatval($t['consideration']);
                $sec_label = $security_id;
            }

            if ($pdf->GetY() > 255) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 7);
                $pdf->SetFillColor(230, 230, 230);
                $pdf->Cell($colSide, 6, 'Side', 1, 0, 'C', true);
                $pdf->Cell($colSecurity, 6, 'Security', 1, 0, 'C', true);
                $pdf->Cell($colQty, 6, 'Qty', 1, 0, 'C', true);
                $pdf->Cell($colPrice, 6, 'Price', 1, 0, 'C', true);
                $pdf->Cell($colConsider, 6, 'Consideration', 1, 1, 'C', true);
                $pdf->SetFont('helvetica', '', 7);
            }

            $pdf->Cell($colSide, 5, strtoupper($trade_side), 1, 0, 'C');
            $pdf->Cell($colSecurity, 5, $sec_label, 1, 0, 'L');
            $pdf->Cell($colQty, 5, number_format($qty, 0), 1, 0, 'R');
            $pdf->Cell($colPrice, 5, number_format($price, 4), 1, 0, 'R');
            $pdf->Cell($colConsider, 5, number_format($consider, 2), 1, 1, 'R');
        }

        // Fee summary block
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell($summaryLeft, 5, '', 0, 0, 'L');
        $pdf->Cell($labelW, 5, 'Total Consideration:', 0, 0, 'R');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell($valueW, 5, number_format($client_total_consider, 2), 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell($summaryLeft, 5, '', 0, 0, 'L');
        $pdf->Cell($labelW, 5, 'Brokerage:', 0, 0, 'R');
        $pdf->Cell($valueW, 5, number_format($client_brokerage, 2), 0, 1, 'R');

        $pdf->Cell($summaryLeft, 5, '', 0, 0, 'L');
        $pdf->Cell($labelW, 5, 'Bank Charges:', 0, 0, 'R');
        $pdf->Cell($valueW, 5, number_format($client_bank, 2), 0, 1, 'R');

        $pdf->Cell($summaryLeft, 5, '', 0, 0, 'L');
        $pdf->Cell($labelW, 5, 'Total Fees:', 0, 0, 'R');
        $pdf->Cell($valueW, 5, number_format($client_totfees, 2), 0, 1, 'R');

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($summaryLeft, 6, '', 0, 0, 'L');
        $pdf->Cell($labelW, 6, 'NET AMOUNT RECEIVABLE:', 0, 0, 'R');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($valueW, 6, number_format($client_net, 2), 0, 1, 'R');

        $pdf->Ln(4);
    }

    // Grand total summary
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell($summaryLeft, 7, '', 0, 0, 'L');
    $pdf->Cell($labelW, 7, 'GRAND TOTAL CONSIDERATION:', 1, 0, 'R', true);
    $pdf->Cell($valueW, 7, number_format($grand_consider, 2), 1, 1, 'R', true);
    $pdf->Cell($summaryLeft, 7, '', 0, 0, 'L');
    $pdf->Cell($labelW, 7, 'GRAND TOTAL BROKERAGE:', 1, 0, 'R', true);
    $pdf->Cell($valueW, 7, number_format($grand_brokerage, 2), 1, 1, 'R', true);
    $pdf->Cell($summaryLeft, 7, '', 0, 0, 'L');
    $pdf->Cell($labelW, 7, 'GRAND TOTAL BANK CHARGES:', 1, 0, 'R', true);
    $pdf->Cell($valueW, 7, number_format($grand_bank, 2), 1, 1, 'R', true);
    $pdf->Cell($summaryLeft, 7, '', 0, 0, 'L');
    $pdf->Cell($labelW, 7, 'GRAND TOTAL FEES:', 1, 0, 'R', true);
    $pdf->Cell($valueW, 7, number_format($grand_totfees, 2), 1, 1, 'R', true);
    $pdf->Cell($summaryLeft, 7, '', 0, 0, 'L');
    $pdf->Cell($labelW, 7, 'GRAND NET RECEIVABLE:', 1, 0, 'R', true);
    $pdf->Cell($valueW, 7, number_format($grand_net, 2), 1, 1, 'R', true);

    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->Cell(0, 4, '* Multiple trades averaged per security', 0, 1, 'L');

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
