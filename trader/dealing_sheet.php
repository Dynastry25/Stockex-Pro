<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/dealing_sheet_helpers.php';

require_trader();
require_mandate();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$company = dealingSheetGetCompany($db);
$view = $_GET['view'] ?? 'all';

dealingSheetEnsureSchema($db);

function dealingSheetDefaultFormData($currentUser, $company)
{
    return [
        'id' => '',
        'sheet_reference' => '',
        'trade_id' => '',
        'trade_reference' => '',
        'lifecycle_stage' => 'draft',
        'approval_status' => 'pending',
        'order_type' => 'buy',
        'asset_class' => 'equity',
        'security_id' => '',
        'security_name' => '',
        'client_name' => '',
        'client_cds_account' => '',
        'broker_code' => $company['company_code'] ?? '',
        'broker_name' => $company['company_name'] ?? '',
        'quantity' => '',
        'order_price' => '',
        'order_value' => '',
        'order_date' => date('Y-m-d'),
        'order_time' => date('H:i:s'),
        'executed_quantity' => '',
        'executed_price' => '',
        'executed_value' => '',
        'trade_date' => date('Y-m-d'),
        'settlement_date' => date('Y-m-d', strtotime('+2 days')),
        'execution_time' => date('H:i:s'),
        'brokerage_fee' => 0,
        'cmsa_fee' => 0,
        'dse_fee' => 0,
        'cds_fee' => 0,
        'vrf_fee' => 0,
        'vat_fee' => 0,
        'total_charges' => 0,
        'payment_method' => 'BANK',
        'payment_reference' => '',
        'payment_status' => 'pending',
        'contract_note_status' => 'pending',
        'dealer_name' => dealingSheetGetCurrentUserDisplayName($currentUser),
        'dealer_signature' => '',
        'execution_notes' => '',
        'approval_notes' => '',
        'remarks' => '',
        'trade_status' => '',
        'trade_settlement_status' => '',
    ];
}

$formOverride = null;

if (isset($_GET['print'])) {
    $printSheet = dealingSheetPrintableRecord($db, (int) $_GET['print']);
    if (!$printSheet) {
        http_response_code(404);
        echo 'Dealing sheet not found.';
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Dealing Sheet <?php echo htmlspecialchars($printSheet['sheet_reference']); ?></title>
        <style>
            body { font-family: Arial, sans-serif; color: #111827; margin: 32px; line-height: 1.4; }
            h1 { margin-bottom: 4px; font-size: 24px; }
            h2 { margin-top: 24px; margin-bottom: 8px; font-size: 16px; }
            .meta { color: #4b5563; margin-bottom: 18px; }
            .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; }
            .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; }
            .label { color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
            .value { font-weight: 600; font-size: 15px; }
            .full { grid-column: 1 / -1; }
        </style>
    </head>
    <body <?php echo isset($_GET['autoprint']) ? 'onload="window.print()"' : ''; ?>>
        <h1>DEALING SHEET (INTERNAL USE)</h1>
        <div class="meta">
            Company Name: <?php echo htmlspecialchars($printSheet['company_name']); ?><br>
            Broker Code: <?php echo htmlspecialchars($printSheet['company_code']); ?><br>
            Department: Operations<br>
            Date: <?php echo htmlspecialchars(format_date($printSheet['order_date'] ?: date('Y-m-d'))); ?>
        </div>

        <div class="card">
            <h2>1. Client Details</h2>
            <div class="grid">
                <div><div class="label">Client Name</div><div class="value"><?php echo htmlspecialchars($printSheet['client_name']); ?></div></div>
                <div><div class="label">CDS Account No</div><div class="value"><?php echo htmlspecialchars($printSheet['client_cds_account']); ?></div></div>
            </div>
        </div>

        <div class="card">
            <h2>2. Order Details</h2>
            <div class="grid">
                <div><div class="label">Order Type</div><div class="value"><?php echo strtoupper(htmlspecialchars($printSheet['order_type'])); ?></div></div>
                <div><div class="label">Security</div><div class="value"><?php echo htmlspecialchars($printSheet['security_id']); ?> - <?php echo htmlspecialchars($printSheet['security_name']); ?></div></div>
                <div><div class="label">Quantity</div><div class="value"><?php echo number_format((float) $printSheet['quantity']); ?></div></div>
                <div><div class="label">Price (Tsh)</div><div class="value"><?php echo number_format((float) $printSheet['order_price'], 2); ?></div></div>
                <div><div class="label">Order Date</div><div class="value"><?php echo htmlspecialchars(format_date($printSheet['order_date'])); ?></div></div>
                <div><div class="label">Order Time</div><div class="value"><?php echo htmlspecialchars($printSheet['order_time']); ?></div></div>
            </div>
        </div>

        <div class="card">
            <h2>3. Execution Details</h2>
            <div class="grid">
                <div><div class="label">Executed Quantity</div><div class="value"><?php echo number_format((float) ($printSheet['executed_quantity'] ?: 0)); ?></div></div>
                <div><div class="label">Executed Price</div><div class="value"><?php echo number_format((float) ($printSheet['executed_price'] ?: 0), 2); ?></div></div>
                <div><div class="label">Total Trade Value</div><div class="value"><?php echo number_format((float) ($printSheet['executed_value'] ?: 0), 2); ?></div></div>
                <div><div class="label">Trade Reference</div><div class="value"><?php echo htmlspecialchars($printSheet['trade_reference'] ?: 'Pending'); ?></div></div>
                <div><div class="label">Trade Date</div><div class="value"><?php echo htmlspecialchars($printSheet['trade_date'] ? format_date($printSheet['trade_date']) : 'Pending'); ?></div></div>
                <div><div class="label">Settlement Date</div><div class="value"><?php echo htmlspecialchars($printSheet['settlement_date'] ? format_date($printSheet['settlement_date']) : 'Pending'); ?></div></div>
            </div>
        </div>

        <div class="card">
            <h2>4. Charges</h2>
            <div class="grid">
                <div><div class="label">Brokerage Fee</div><div class="value"><?php echo number_format((float) $printSheet['brokerage_fee'], 2); ?></div></div>
                <div><div class="label">VAT</div><div class="value"><?php echo number_format((float) $printSheet['vat_fee'], 2); ?></div></div>
                <div><div class="label">CMSA Fee</div><div class="value"><?php echo number_format((float) $printSheet['cmsa_fee'], 2); ?></div></div>
                <div><div class="label">DSE Fee</div><div class="value"><?php echo number_format((float) $printSheet['dse_fee'], 2); ?></div></div>
                <div><div class="label">CDS Fee</div><div class="value"><?php echo number_format((float) $printSheet['cds_fee'], 2); ?></div></div>
                <div><div class="label">VRF Fee</div><div class="value"><?php echo number_format((float) $printSheet['vrf_fee'], 2); ?></div></div>
                <div class="full"><div class="label">Total Charges</div><div class="value"><?php echo number_format((float) $printSheet['total_charges'], 2); ?></div></div>
            </div>
        </div>

        <div class="card">
            <h2>5. Payment & Controls</h2>
            <div class="grid">
                <div><div class="label">Payment Method</div><div class="value"><?php echo htmlspecialchars($printSheet['payment_method'] ?: 'Pending'); ?></div></div>
                <div><div class="label">Payment Status</div><div class="value"><?php echo htmlspecialchars(ucfirst($printSheet['payment_status'])); ?></div></div>
                <div><div class="label">Reference</div><div class="value"><?php echo htmlspecialchars($printSheet['payment_reference'] ?: ''); ?></div></div>
                <div><div class="label">Lifecycle</div><div class="value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $printSheet['lifecycle_stage']))); ?></div></div>
                <div><div class="label">Dealer Name</div><div class="value"><?php echo htmlspecialchars($printSheet['dealer_name'] ?: ''); ?></div></div>
                <div><div class="label">Execution Time</div><div class="value"><?php echo htmlspecialchars($printSheet['execution_time'] ?: ''); ?></div></div>
                <div><div class="label">Checked By</div><div class="value"><?php echo htmlspecialchars($printSheet['checked_by_name'] ?: '__________________'); ?></div></div>
                <div><div class="label">Approved By</div><div class="value"><?php echo htmlspecialchars($printSheet['approved_by_name'] ?: '__________________'); ?></div></div>
                <div class="full"><div class="label">Remarks</div><div class="value"><?php echo nl2br(htmlspecialchars($printSheet['remarks'] ?: '')); ?></div></div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'generate_contract_note') {
    $sheet = dealingSheetGetById($db, (int) $_GET['id']);
    if ($sheet && !empty($sheet['trade_id'])) {
        dealingSheetTransition($db, $sheet['id'], 'mark_contract_generated', [], $current_user);
        header('Location: ../api/v1/contract_note.php?trade_id=' . (int) $sheet['trade_id']);
        exit;
    }
    show_alert('This dealing sheet must be executed and linked to a trade before generating a contract note.', 'danger');
    header('Location: dealing_sheet.php?sheet_id=' . (int) $_GET['id']);
    exit;
}

if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'sync') {
    try {
        dealingSheetTransition($db, (int) $_GET['id'], 'sync_trade', [], $current_user);
        show_alert('Dealing sheet synchronized from the linked trade.', 'success');
    } catch (Exception $e) {
        show_alert($e->getMessage(), 'danger');
    }
    header('Location: dealing_sheet.php?sheet_id=' . (int) $_GET['id']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    try {
        $forcedStage = null;
        if ($_POST['form_action'] === 'save_draft') {
            $forcedStage = 'draft';
        } elseif ($_POST['form_action'] === 'record_order') {
            $forcedStage = 'order_recorded';
        }

        $savedSheet = dealingSheetPersist($db, $_POST, $current_user, $forcedStage);
        $savedSheet = dealingSheetTransition($db, $savedSheet['id'], $_POST['form_action'], $_POST, $current_user);

        show_alert('Dealing sheet updated successfully.', 'success');
        header('Location: dealing_sheet.php?sheet_id=' . (int) $savedSheet['id'] . '&view=' . urlencode($view));
        exit;
    } catch (Exception $e) {
        show_alert($e->getMessage(), 'danger');
        $formOverride = array_merge(
            dealingSheetDefaultFormData($current_user, $company),
            dealingSheetSanitizePayload($_POST)
        );
    }
}

$selectedSheet = dealingSheetDefaultFormData($current_user, $company);
if ($formOverride !== null) {
    $selectedSheet = $formOverride;
} elseif (!empty($_GET['sheet_id'])) {
    $selectedSheet = dealingSheetGetById($db, (int) $_GET['sheet_id']) ?: $selectedSheet;
} elseif (!empty($_GET['trade_id'])) {
    $fromTrade = dealingSheetBuildFromTrade($db, (int) $_GET['trade_id'], $current_user);
    if ($fromTrade) {
        $selectedSheet = $fromTrade;
    }
}

$history = !empty($selectedSheet['id']) ? dealingSheetGetHistory($db, $selectedSheet['id']) : [];
$clients = dealingSheetGetClients($db);
$securities = dealingSheetGetSecurities($db);
$paymentMethods = dealingSheetGetPaymentMethods($db);
$overview = dealingSheetOverview($db);

$filters = [
    'view' => $view,
    'search' => $_GET['search'] ?? '',
    'stage' => $_GET['stage'] ?? 'all',
    'asset_class' => $_GET['asset_class'] ?? 'all',
    'payment_status' => $_GET['payment_status'] ?? 'all',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];
$sheets = dealingSheetList($db, $filters);

$page_title = $view === 'orders' ? 'Order Intake Sheet' : 'Dealing Sheet Lifecycle';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="page-title mb-1"><?php echo htmlspecialchars($page_title); ?></h1>
                <p class="page-subtitle mb-0">Capture orders, execute trades, approve internally, generate contract notes, and track settlement from one workflow.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="dealing_sheet.php?new=1" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>New Sheet</a>
                <a href="order_sheet.php" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-2"></i>Order Intake View</a>
                <a href="trades.php" class="btn btn-outline-dark"><i class="bi bi-list-ul me-2"></i>All Trades</a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php display_alerts(); ?>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php foreach (['all' => 'All Sheets', 'orders' => 'Order Intake', 'execution' => 'Execution Queue', 'approved' => 'Approved', 'settled' => 'Settled'] as $viewKey => $viewLabel): ?>
            <a href="dealing_sheet.php?view=<?php echo urlencode($viewKey); ?>" class="btn <?php echo $view === $viewKey ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                <?php echo htmlspecialchars($viewLabel); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6"><div class="card dashboard-card"><div class="card-body"><div class="text-muted small">Total Sheets</div><div class="fs-3 fw-bold"><?php echo number_format($overview['total']); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card dashboard-card"><div class="card-body"><div class="text-muted small">Open Orders</div><div class="fs-3 fw-bold text-primary"><?php echo number_format($overview['orders']); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card dashboard-card"><div class="card-body"><div class="text-muted small">Awaiting Review</div><div class="fs-3 fw-bold text-warning"><?php echo number_format($overview['execution']); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card dashboard-card"><div class="card-body"><div class="text-muted small">Settled</div><div class="fs-3 fw-bold text-success"><?php echo number_format($overview['settled']); ?></div><div class="small text-muted mt-2">TZS <?php echo number_format($overview['total_executed_value'], 2); ?> tracked</div></div></div></div>
    </div>

    <div class="card dashboard-card mb-4">
        <div class="card-header bg-transparent border-0 pb-0"><h6 class="mb-0 fw-semibold"><?php echo !empty($selectedSheet['id']) ? 'Edit Dealing Sheet' : 'Create Dealing Sheet'; ?></h6></div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) ($selectedSheet['id'] ?? '')); ?>">
                <input type="hidden" name="trade_id" value="<?php echo htmlspecialchars((string) ($selectedSheet['trade_id'] ?? '')); ?>">
                <input type="hidden" name="trade_reference" value="<?php echo htmlspecialchars((string) ($selectedSheet['trade_reference'] ?? '')); ?>">
                <div class="row g-3">
                    <div class="col-lg-2"><label class="form-label fw-semibold">Sheet Ref</label><input class="form-control" value="<?php echo htmlspecialchars($selectedSheet['sheet_reference'] ?: 'Will be generated on save'); ?>" readonly></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Order Type</label><select class="form-select" name="order_type"><option value="buy" <?php echo ($selectedSheet['order_type'] === 'buy') ? 'selected' : ''; ?>>BUY</option><option value="sell" <?php echo ($selectedSheet['order_type'] === 'sell') ? 'selected' : ''; ?>>SELL</option></select></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Asset Class</label><select class="form-select" name="asset_class"><option value="equity" <?php echo ($selectedSheet['asset_class'] === 'equity') ? 'selected' : ''; ?>>Equity</option><option value="bond" <?php echo ($selectedSheet['asset_class'] === 'bond') ? 'selected' : ''; ?>>Bond</option></select></div>
                    <div class="col-lg-3"><label class="form-label fw-semibold">Client Name</label><input class="form-control" name="client_name" list="clientNames" value="<?php echo htmlspecialchars($selectedSheet['client_name']); ?>" required></div>
                    <div class="col-lg-3"><label class="form-label fw-semibold">CDS Account</label><input class="form-control" name="client_cds_account" list="clientAccounts" value="<?php echo htmlspecialchars($selectedSheet['client_cds_account']); ?>" required></div>
                    <div class="col-lg-3"><label class="form-label fw-semibold">Security ID</label><input class="form-control" name="security_id" list="securityIds" value="<?php echo htmlspecialchars($selectedSheet['security_id']); ?>" required></div>
                    <div class="col-lg-3"><label class="form-label fw-semibold">Security Name</label><input class="form-control" name="security_name" value="<?php echo htmlspecialchars($selectedSheet['security_name']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Qty</label><input type="number" class="form-control" name="quantity" value="<?php echo htmlspecialchars((string) $selectedSheet['quantity']); ?>" min="1" step="1" required></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Order Price</label><input type="number" class="form-control" name="order_price" value="<?php echo htmlspecialchars((string) $selectedSheet['order_price']); ?>" min="0" step="0.0001" required></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Order Date</label><input type="date" class="form-control" name="order_date" value="<?php echo htmlspecialchars((string) $selectedSheet['order_date']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Order Time</label><input type="time" class="form-control" name="order_time" value="<?php echo htmlspecialchars(substr((string) $selectedSheet['order_time'], 0, 5)); ?>"></div>
                    <div class="col-lg-3"><label class="form-label fw-semibold">Broker Code</label><input class="form-control" name="broker_code" value="<?php echo htmlspecialchars($selectedSheet['broker_code']); ?>"></div>
                    <div class="col-lg-3"><label class="form-label fw-semibold">Dealer Name</label><input class="form-control" name="dealer_name" value="<?php echo htmlspecialchars($selectedSheet['dealer_name']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Exec Qty</label><input type="number" class="form-control" name="executed_quantity" value="<?php echo htmlspecialchars((string) ($selectedSheet['executed_quantity'] ?? '')); ?>" min="0" step="1"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Exec Price</label><input type="number" class="form-control" name="executed_price" value="<?php echo htmlspecialchars((string) ($selectedSheet['executed_price'] ?? '')); ?>" min="0" step="0.0001"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Trade Date</label><input type="date" class="form-control" name="trade_date" value="<?php echo htmlspecialchars((string) $selectedSheet['trade_date']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Settlement</label><input type="date" class="form-control" name="settlement_date" value="<?php echo htmlspecialchars((string) $selectedSheet['settlement_date']); ?>"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Execution Time</label><input type="time" class="form-control" name="execution_time" value="<?php echo htmlspecialchars(substr((string) $selectedSheet['execution_time'], 0, 5)); ?>"></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Payment Method</label><select class="form-select" name="payment_method"><?php foreach ($paymentMethods as $method): ?><option value="<?php echo htmlspecialchars($method['code']); ?>" <?php echo ($selectedSheet['payment_method'] === $method['code']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($method['description']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-lg-2"><label class="form-label fw-semibold">Payment Status</label><select class="form-select" name="payment_status"><?php foreach (['pending','paid','failed','linked'] as $status): ?><option value="<?php echo $status; ?>" <?php echo ($selectedSheet['payment_status'] === $status) ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
                    <div class="col-lg-4"><label class="form-label fw-semibold">Payment Reference</label><input class="form-control" name="payment_reference" value="<?php echo htmlspecialchars($selectedSheet['payment_reference']); ?>"></div>
                    <div class="col-lg-12"><label class="form-label fw-semibold">Remarks</label><textarea class="form-control" name="remarks" rows="2"><?php echo htmlspecialchars($selectedSheet['remarks']); ?></textarea></div>
                    <div class="col-lg-6"><label class="form-label fw-semibold">Execution Notes</label><textarea class="form-control" name="execution_notes" rows="2"><?php echo htmlspecialchars($selectedSheet['execution_notes']); ?></textarea></div>
                    <div class="col-lg-6"><label class="form-label fw-semibold">Approval Notes</label><textarea class="form-control" name="approval_notes" rows="2"><?php echo htmlspecialchars($selectedSheet['approval_notes']); ?></textarea></div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-lg-2"><div class="small text-muted">Brokerage</div><div class="fw-semibold">TZS <?php echo number_format((float) $selectedSheet['brokerage_fee'], 2); ?></div></div>
                    <div class="col-lg-2"><div class="small text-muted">VAT</div><div class="fw-semibold">TZS <?php echo number_format((float) $selectedSheet['vat_fee'], 2); ?></div></div>
                    <div class="col-lg-2"><div class="small text-muted">CMSA</div><div class="fw-semibold">TZS <?php echo number_format((float) $selectedSheet['cmsa_fee'], 2); ?></div></div>
                    <div class="col-lg-2"><div class="small text-muted">DSE</div><div class="fw-semibold">TZS <?php echo number_format((float) $selectedSheet['dse_fee'], 2); ?></div></div>
                    <div class="col-lg-2"><div class="small text-muted">CDS/VRF</div><div class="fw-semibold">TZS <?php echo number_format((float) $selectedSheet['cds_fee'] + (float) $selectedSheet['vrf_fee'], 2); ?></div></div>
                    <div class="col-lg-2"><div class="small text-muted">Total Charges</div><div class="fw-bold text-success">TZS <?php echo number_format((float) $selectedSheet['total_charges'], 2); ?></div></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-outline-secondary" type="submit" name="form_action" value="save_draft">Save Draft</button>
                    <button class="btn btn-primary" type="submit" name="form_action" value="record_order">Record Order</button>
                    <button class="btn btn-info" type="submit" name="form_action" value="execute">Execute & Sync Trade</button>
                    <button class="btn btn-warning" type="submit" name="form_action" value="mark_checked">Mark Checked</button>
                    <button class="btn btn-success" type="submit" name="form_action" value="approve">Approve</button>
                    <button class="btn btn-outline-warning" type="submit" name="form_action" value="reject">Reject</button>
                    <button class="btn btn-dark" type="submit" name="form_action" value="mark_contract_sent">Mark Contract Sent</button>
                    <button class="btn btn-outline-success" type="submit" name="form_action" value="mark_paid">Mark Paid</button>
                    <button class="btn btn-outline-danger" type="submit" name="form_action" value="cancel">Cancel Sheet</button>
                    <?php if (!empty($selectedSheet['id'])): ?>
                        <a class="btn btn-outline-secondary" href="dealing_sheet.php?print=<?php echo (int) $selectedSheet['id']; ?>&autoprint=1" target="_blank">Print</a>
                    <?php endif; ?>
                    <?php if (!empty($selectedSheet['trade_id'])): ?>
                        <a class="btn btn-outline-primary" href="dealing_sheet.php?action=generate_contract_note&id=<?php echo (int) $selectedSheet['id']; ?>" target="_blank">Contract Note</a>
                        <a class="btn btn-outline-secondary" href="dealing_sheet.php?action=sync&id=<?php echo (int) $selectedSheet['id']; ?>">Sync Trade Status</a>
                        <a class="btn btn-outline-dark" href="view_trade?id=<?php echo (int) $selectedSheet['trade_id']; ?>">Linked Trade</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if (!empty($history)): ?>
                <hr>
                <h6 class="fw-semibold">Lifecycle History</h6>
                <div class="small text-muted">
                    <?php foreach (array_slice($history, 0, 6) as $item): ?>
                        <div class="mb-1"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($item['created_at'])) . ' - ' . strtoupper($item['action_type']) . ' - ' . ($item['performed_by_name'] ?: 'System')); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card dashboard-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <div class="col-lg-3"><label class="form-label fw-semibold">Search</label><input class="form-control" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Ref, client, CDS, security"></div>
                <div class="col-lg-2"><label class="form-label fw-semibold">Stage</label><select class="form-select" name="stage"><?php foreach (['all','draft','order_recorded','executed','checked','approved','contracted','settled','rejected','cancelled'] as $stage): ?><option value="<?php echo $stage; ?>" <?php echo ($filters['stage'] === $stage) ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $stage)); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2"><label class="form-label fw-semibold">Asset</label><select class="form-select" name="asset_class"><option value="all">All</option><option value="equity" <?php echo ($filters['asset_class'] === 'equity') ? 'selected' : ''; ?>>Equity</option><option value="bond" <?php echo ($filters['asset_class'] === 'bond') ? 'selected' : ''; ?>>Bond</option></select></div>
                <div class="col-lg-2"><label class="form-label fw-semibold">Payment</label><select class="form-select" name="payment_status"><?php foreach (['all','pending','paid','failed','linked'] as $status): ?><option value="<?php echo $status; ?>" <?php echo ($filters['payment_status'] === $status) ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-1"><label class="form-label fw-semibold">From</label><input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"></div>
                <div class="col-lg-1"><label class="form-label fw-semibold">To</label><input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"></div>
                <div class="col-lg-1"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
            </form>
        </div>
    </div>

    <div class="card dashboard-card">
        <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="mb-1 fw-semibold">Dealing Sheet Register</h6>
                <div class="small text-muted"><?php echo number_format(count($sheets)); ?> sheets matching the current view</div>
            </div>
            <div class="small text-muted">
                Internal control trail for order capture, execution, approval, contract notes, and settlement follow-up.
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Client</th>
                            <th>Security</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Order Price</th>
                            <th class="text-end">Executed Value</th>
                            <th>Lifecycle</th>
                            <th>Payment</th>
                            <th>Trade</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sheets)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">No dealing sheets match the current filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sheets as $sheet): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($sheet['sheet_reference']); ?></div>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars(format_date($sheet['order_date'] ?: date('Y-m-d'))); ?>
                                            <?php if (!empty($sheet['order_time'])): ?>
                                                at <?php echo htmlspecialchars(substr((string) $sheet['order_time'], 0, 5)); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($sheet['client_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($sheet['client_cds_account']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($sheet['security_id']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($sheet['security_name'] ?: $sheet['asset_class']); ?></div>
                                    </td>
                                    <td class="text-end"><?php echo number_format((float) ($sheet['executed_quantity'] ?: $sheet['quantity'] ?: 0)); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($sheet['order_price'] ?: 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($sheet['executed_value'] ?: $sheet['order_value'] ?: 0), 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo dealingSheetStageBadgeClass($sheet['lifecycle_stage']); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $sheet['lifecycle_stage']))); ?>
                                        </span>
                                        <?php if (!empty($sheet['approved_by_name'])): ?>
                                            <div class="small text-muted mt-1">Approved by <?php echo htmlspecialchars($sheet['approved_by_name']); ?></div>
                                        <?php elseif (!empty($sheet['checked_by_name'])): ?>
                                            <div class="small text-muted mt-1">Checked by <?php echo htmlspecialchars($sheet['checked_by_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo in_array($sheet['payment_status'], ['paid', 'linked'], true) ? 'success' : ($sheet['payment_status'] === 'failed' ? 'danger' : 'secondary'); ?>">
                                            <?php echo htmlspecialchars(ucfirst($sheet['payment_status'])); ?>
                                        </span>
                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars($sheet['contract_note_status'] ?: 'pending'); ?> contract</div>
                                    </td>
                                    <td>
                                        <?php if (!empty($sheet['trade_reference'])): ?>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($sheet['trade_reference']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($sheet['trade_status'] ?: 'synced'); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small">Not synced</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="dealing_sheet.php?sheet_id=<?php echo (int) $sheet['id']; ?>&view=<?php echo urlencode($view); ?>" class="btn btn-outline-primary btn-sm" title="Open Sheet">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <?php if (!empty($sheet['trade_id'])): ?>
                                                <a href="dealing_sheet.php?action=sync&id=<?php echo (int) $sheet['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Sync from Trade">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </a>
                                                <a href="view_trade?id=<?php echo (int) $sheet['trade_id']; ?>" class="btn btn-outline-dark btn-sm" title="Open Trade">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="dealing_sheet.php?print=<?php echo (int) $sheet['id']; ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Print Sheet">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<datalist id="clientNames">
    <?php foreach ($clients as $client): ?>
        <option value="<?php echo htmlspecialchars($client['client_name']); ?>">
    <?php endforeach; ?>
</datalist>

<datalist id="clientAccounts">
    <?php foreach ($clients as $client): ?>
        <?php if (!empty($client['client_cds_account'])): ?>
            <option value="<?php echo htmlspecialchars($client['client_cds_account']); ?>">
        <?php endif; ?>
    <?php endforeach; ?>
</datalist>

<datalist id="securityIds">
    <?php foreach ($securities as $security): ?>
        <option value="<?php echo htmlspecialchars($security['security_id']); ?>" label="<?php echo htmlspecialchars($security['security_name'] ?: $security['security_id']); ?>">
    <?php endforeach; ?>
</datalist>

<?php include '../includes/footer.php'; ?>
