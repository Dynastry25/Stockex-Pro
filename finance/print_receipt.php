<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$receipt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($receipt_id <= 0) {
    die('Invalid receipt ID');
}

$db = getDBConnection();

$stmt = $db->prepare("
    SELECT tr.*, t.trade_reference, t.trade_date, t.settlement_date, t.asset_class,
           u.full_name as generated_by_name,
           ss1.setting_value as company_name,
           ss2.setting_value as system_name,
           c.logo_path, c.header_image_path, c.footer_image_path
    FROM trade_receipts tr
    JOIN trades t ON tr.trade_id = t.id
    JOIN users u ON tr.generated_by = u.id
    CROSS JOIN system_settings ss1
    CROSS JOIN system_settings ss2
    LEFT JOIN companies c ON c.id = 1
    WHERE tr.id = ? AND ss1.setting_key = 'company_name' AND ss2.setting_key = 'system_name'
    LIMIT 1
");
$stmt->execute([$receipt_id]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die('Receipt not found');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo htmlspecialchars($receipt['receipt_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .container { max-width: none !important; }
        }
        .receipt-header {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }
        .receipt-footer {
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
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer"></i> Print Receipt
                </button>
                <button onclick="window.close()" class="btn btn-secondary">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>
        </div>
        
        <!-- Added header image display -->
        <?php if (!empty($receipt['header_image_path']) && file_exists('../' . $receipt['header_image_path'])): ?>
            <div class="text-center mb-3">
                <img src="../<?php echo htmlspecialchars($receipt['header_image_path']); ?>" 
                     alt="Header" class="header-image">
            </div>
        <?php endif; ?>
        
        <div class="receipt-header text-center">
            <!-- Added company logo display -->
            <?php if (!empty($receipt['logo_path']) && file_exists('../' . $receipt['logo_path'])): ?>
                <div class="mb-3">
                    <img src="../<?php echo htmlspecialchars($receipt['logo_path']); ?>" 
                         alt="Company Logo" class="company-logo">
                </div>
            <?php endif; ?>
            
            <h2><?php echo htmlspecialchars($receipt['company_name']); ?></h2>
            <h4 class="text-primary"><?php echo htmlspecialchars($receipt['system_name']); ?></h4>
            <h3 class="mt-3">TRADE RECEIPT</h3>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <h6>Receipt Details</h6>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>Receipt Number:</strong></td>
                        <td><?php echo htmlspecialchars($receipt['receipt_number']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Receipt Date:</strong></td>
                        <td><?php echo format_date($receipt['receipt_date']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Trade Reference:</strong></td>
                        <td><?php echo htmlspecialchars($receipt['trade_reference']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Trade Date:</strong></td>
                        <td><?php echo format_date($receipt['trade_date']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Settlement Date:</strong></td>
                        <td><?php echo format_date($receipt['settlement_date']); ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6>Buyer Information</h6>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>Name:</strong></td>
                        <!-- Updated to use client_name -->
                        <td><?php echo htmlspecialchars($receipt['client_name']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Account:</strong></td>
                        <!-- Updated to use client_cds_account -->
                        <td><?php echo htmlspecialchars($receipt['client_cds_account']); ?></td>
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
                            <td><?php echo htmlspecialchars($receipt['security_name']); ?></td>
                            <td><?php echo number_format($receipt['quantity']); ?></td>
                            <td>$<?php echo format_currency($receipt['unit_price']); ?></td>
                            <!-- Updated to use gross_amount -->
                            <td>$<?php echo format_currency($receipt['gross_amount']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 offset-md-6">
                <table class="table">
                    <tr>
                        <td><strong>Subtotal:</strong></td>
                        <!-- Updated to use gross_amount -->
                        <td class="text-end">$<?php echo format_currency($receipt['gross_amount']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Fees:</strong></td>
                        <td class="text-end">$<?php echo format_currency($receipt['fees']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Taxes:</strong></td>
                        <td class="text-end">$<?php echo format_currency($receipt['taxes']); ?></td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Net Amount:</strong></td>
                        <td class="text-end"><strong>$<?php echo format_currency($receipt['net_amount']); ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="receipt-footer">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        Generated by: <?php echo htmlspecialchars($receipt['generated_by_name']); ?><br>
                        Generated on: <?php echo format_date($receipt['created_at']); ?>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        This is a computer-generated receipt.<br>
                        No signature required.
                    </small>
                </div>
            </div>
            
            <!-- Added footer image display -->
            <?php if (!empty($receipt['footer_image_path']) && file_exists('../' . $receipt['footer_image_path'])): ?>
                <div class="text-center">
                    <img src="../<?php echo htmlspecialchars($receipt['footer_image_path']); ?>" 
                         alt="Footer" class="footer-image">
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
