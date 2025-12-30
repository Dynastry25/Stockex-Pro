<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($invoice_id <= 0) {
    die('Invalid invoice ID');
}

$db = getDBConnection();

$stmt = $db->prepare("
    SELECT ti.*, t.trade_reference, t.trade_date, t.settlement_date, t.asset_class,
           u.full_name as generated_by_name,
           ss1.setting_value as company_name,
           ss2.setting_value as system_name,
           c.logo_path, c.header_image_path, c.footer_image_path
    FROM trade_invoices ti
    JOIN trades t ON ti.trade_id = t.id
    JOIN users u ON ti.generated_by = u.id
    CROSS JOIN system_settings ss1
    CROSS JOIN system_settings ss2
    LEFT JOIN companies c ON c.id = 1
    WHERE ti.id = ? AND ss1.setting_key = 'company_name' AND ss2.setting_key = 'system_name'
    LIMIT 1
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('Invoice not found');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .container { max-width: none !important; }
        }
        .invoice-header {
            border-bottom: 2px solid #198754;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }
        .invoice-footer {
            border-top: 1px solid #dee2e6;
            padding-top: 1rem;
            margin-top: 2rem;
        }
        /* Added styles for company images */
        .company-logo {
            max-height: 80px;
            max-width: 200px;
            object-fit: contain;
        }
        .header-image {
            max-height: 120px;
            width: 100%;
            object-fit: contain;
            margin-bottom: 1rem;
        }
        .footer-image {
            max-height: 60px;
            width: 100%;
            object-fit: contain;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row no-print mb-3">
            <div class="col-12">
                <button onclick="window.print()" class="btn btn-success">
                    <i class="bi bi-printer"></i> Print Invoice
                </button>
                <button onclick="window.close()" class="btn btn-secondary">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>
        </div>
        
        <!-- Added header image display -->
        <?php if (!empty($invoice['header_image_path']) && file_exists('../' . $invoice['header_image_path'])): ?>
            <div class="text-center mb-3">
                <img src="../<?php echo htmlspecialchars($invoice['header_image_path']); ?>" 
                     alt="Header" class="header-image">
            </div>
        <?php endif; ?>
        
        <div class="invoice-header text-center">
            <!-- Added company logo display -->
            <?php if (!empty($invoice['logo_path']) && file_exists('../' . $invoice['logo_path'])): ?>
                <div class="mb-3">
                    <img src="../<?php echo htmlspecialchars($invoice['logo_path']); ?>" 
                         alt="Company Logo" class="company-logo">
                </div>
            <?php endif; ?>
            
            <h2><?php echo htmlspecialchars($invoice['company_name']); ?></h2>
            <h4 class="text-success"><?php echo htmlspecialchars($invoice['system_name']); ?></h4>
            <h3 class="mt-3">TRADE INVOICE</h3>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <h6>Invoice Details</h6>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>Invoice Number:</strong></td>
                        <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Invoice Date:</strong></td>
                        <td><?php echo format_date($invoice['invoice_date']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Trade Reference:</strong></td>
                        <td><?php echo htmlspecialchars($invoice['trade_reference']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Trade Date:</strong></td>
                        <td><?php echo format_date($invoice['trade_date']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Settlement Date:</strong></td>
                        <td><?php echo format_date($invoice['settlement_date']); ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6>Seller Information</h6>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>Name:</strong></td>
                        <!-- Updated to use client_name -->
                        <td><?php echo htmlspecialchars($invoice['client_name']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Account:</strong></td>
                        <!-- Updated to use client_cds_account -->
                        <td><?php echo htmlspecialchars($invoice['client_cds_account']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Transaction Details</h6>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Instrument</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Gross Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <!-- Updated to use security_name -->
                            <td><?php echo htmlspecialchars($invoice['security_name']); ?></td>
                            <td><?php echo number_format($invoice['quantity']); ?></td>
                            <td>$<?php echo format_currency($invoice['unit_price']); ?></td>
                            <td>$<?php echo format_currency($invoice['gross_amount']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 offset-md-6">
                <table class="table">
                    <tr>
                        <td><strong>Gross Amount:</strong></td>
                        <td class="text-end">$<?php echo format_currency($invoice['gross_amount']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Less: Fees:</strong></td>
                        <td class="text-end">($<?php echo format_currency($invoice['fees']); ?>)</td>
                    </tr>
                    <tr>
                        <td><strong>Less: Taxes:</strong></td>
                        <td class="text-end">($<?php echo format_currency($invoice['taxes']); ?>)</td>
                    </tr>
                    <tr class="table-success">
                        <td><strong>Net Amount:</strong></td>
                        <td class="text-end"><strong>$<?php echo format_currency($invoice['net_amount']); ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="invoice-footer">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        Generated by: <?php echo htmlspecialchars($invoice['generated_by_name']); ?><br>
                        Generated on: <?php echo format_date($invoice['created_at']); ?>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        This is a computer-generated invoice.<br>
                        No signature required.
                    </small>
                </div>
            </div>
            
            <!-- Added footer image display -->
            <?php if (!empty($invoice['footer_image_path']) && file_exists('../' . $invoice['footer_image_path'])): ?>
                <div class="text-center">
                    <img src="../<?php echo htmlspecialchars($invoice['footer_image_path']); ?>" 
                         alt="Footer" class="footer-image">
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
