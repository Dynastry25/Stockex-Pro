<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Only HR staff can access
require_hr();

$db = getDBConnection();

if (isset($_GET['request_id'])) {
    $request_id = (int)$_GET['request_id'];
    
    try {
        // Get request details
        $stmt = $db->prepare("
            SELECT pp.*, 
                   lt.description as pay_to_desc,
                   u1.username as requested_by_name,
                   u1.full_name as requested_by_fullname,
                   u2.username as ceo_approved_by_name,
                   u2.full_name as ceo_approved_by_fullname,
                   u3.username as finance_approved_by_name,
                   u3.full_name as finance_approved_by_fullname,
                   u4.username as paid_by_name
            FROM pending_pay pp
            LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
            LEFT JOIN users u1 ON pp.requested_by = u1.id
            LEFT JOIN users u2 ON pp.ceo_approved_by = u2.id
            LEFT JOIN users u3 ON pp.finance_approved_by = u3.id
            LEFT JOIN users u4 ON pp.paid_by = u4.id
            WHERE pp.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            die('Request not found');
        }
        
        // Generate PDF using FPDF or similar library
        // For this example, we'll create a simple HTML PDF
        // In production, use a proper PDF library like TCPDF or FPDF
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="payment_request_' . $request['request_no'] . '.pdf"');
        
        // For now, output HTML that can be printed as PDF
        // In production, replace this with actual PDF generation
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Payment Request - <?php echo htmlspecialchars($request['request_no']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; color: #333; }
                .document-title { font-size: 20px; margin-top: 10px; color: #666; }
                .request-no { font-size: 18px; margin-top: 5px; color: #007bff; }
                .section { margin-bottom: 20px; }
                .section-title { background-color: #f8f9fa; padding: 10px; border-left: 4px solid #007bff; margin-bottom: 15px; font-weight: bold; }
                .info-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                .info-table td { padding: 8px; border-bottom: 1px solid #dee2e6; }
                .info-table td.label { font-weight: bold; width: 40%; background-color: #f8f9fa; }
                .signature-area { margin-top: 40px; padding-top: 20px; border-top: 1px solid #333; }
                .signature-line { display: inline-block; width: 200px; border-top: 1px solid #333; margin-top: 50px; }
                .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #666; }
                .status-badge { padding: 5px 10px; border-radius: 4px; font-weight: bold; }
                .status-pending { background-color: #ffc107; color: #333; }
                .status-approved { background-color: #28a745; color: white; }
                .status-rejected { background-color: #dc3545; color: white; }
                .status-paid { background-color: #007bff; color: white; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company-name">VICTORY FINANCIAL SERVICES LTD</div>
                <div class="document-title">PAYMENT REQUEST FORM</div>
                <div class="request-no">Request No: <?php echo htmlspecialchars($request['request_no']); ?></div>
                <div>Generated on: <?php echo date('F d, Y H:i:s'); ?></div>
            </div>
            
            <div class="section">
                <div class="section-title">Request Information</div>
                <table class="info-table">
                    <tr>
                        <td class="label">Request Number:</td>
                        <td><?php echo htmlspecialchars($request['request_no']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Request Date:</td>
                        <td><?php echo date('F d, Y', strtotime($request['requested_at'])); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Subject:</td>
                        <td><?php echo htmlspecialchars($request['subject']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Requested By:</td>
                        <td><?php echo htmlspecialchars($request['requested_by_fullname'] ?? $request['requested_by_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Status:</td>
                        <td>
                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                <?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">Payment Details</div>
                <table class="info-table">
                    <tr>
                        <td class="label">Pay To Type:</td>
                        <td><?php echo htmlspecialchars($request['pay_to_desc'] ?? $request['pay_to_type']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Payee Name:</td>
                        <td><?php echo htmlspecialchars($request['payee_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Amount:</td>
                        <td><strong><?php echo number_format($request['amount_paid'], 2); ?> <?php echo htmlspecialchars($request['currency']); ?></strong></td>
                    </tr>
                    <tr>
                        <td class="label">Currency:</td>
                        <td><?php echo htmlspecialchars($request['currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Cheque No:</td>
                        <td><?php echo htmlspecialchars($request['cheque_no'] ?: 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Description:</td>
                        <td><?php echo nl2br(htmlspecialchars($request['payment_description'] ?: 'N/A')); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">Bank Details</div>
                <table class="info-table">
                    <tr>
                        <td class="label">Bank Name:</td>
                        <td><?php echo htmlspecialchars($request['payee_bank_name'] ?: 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Branch:</td>
                        <td><?php echo htmlspecialchars($request['payee_branch'] ?: 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Account Name:</td>
                        <td><?php echo htmlspecialchars($request['payee_account_name'] ?: 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Account Number:</td>
                        <td><?php echo htmlspecialchars($request['payee_account_no']); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">Approval Status</div>
                <table class="info-table">
                    <tr>
                        <td class="label">CEO Approval:</td>
                        <td>
                            <?php if ($request['ceo_approved_at']): ?>
                                Approved on <?php echo date('F d, Y', strtotime($request['ceo_approved_at'])); ?>
                                by <?php echo htmlspecialchars($request['ceo_approved_by_fullname'] ?? $request['ceo_approved_by_name']); ?>
                            <?php else: ?>
                                <span class="status-badge status-pending">PENDING</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label">Finance Approval:</td>
                        <td>
                            <?php if ($request['finance_approved_at']): ?>
                                Approved on <?php echo date('F d, Y', strtotime($request['finance_approved_at'])); ?>
                                by <?php echo htmlspecialchars($request['finance_approved_by_fullname'] ?? $request['finance_approved_by_name']); ?>
                            <?php else: ?>
                                <span class="status-badge status-pending">PENDING</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label">Payment Status:</td>
                        <td>
                            <?php if ($request['paid_at']): ?>
                                Paid on <?php echo date('F d, Y', strtotime($request['paid_at'])); ?>
                            <?php else: ?>
                                <span class="status-badge status-pending">NOT PAID</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="signature-area">
                <div style="float: left; width: 30%; text-align: center;">
                    <div class="signature-line"></div>
                    <div>Prepared By</div>
                    <div><?php echo htmlspecialchars($request['requested_by_fullname'] ?? $request['requested_by_name']); ?></div>
                    <div><?php echo date('F d, Y', strtotime($request['requested_at'])); ?></div>
                </div>
                
                <div style="float: left; width: 30%; margin-left: 5%; text-align: center;">
                    <div class="signature-line"></div>
                    <div>CEO Approval</div>
                    <div><?php echo $request['ceo_approved_by_fullname'] ?? 'Pending'; ?></div>
                    <div><?php echo $request['ceo_approved_at'] ? date('F d, Y', strtotime($request['ceo_approved_at'])) : 'Pending'; ?></div>
                </div>
                
                <div style="float: left; width: 30%; margin-left: 5%; text-align: center;">
                    <div class="signature-line"></div>
                    <div>Finance Approval</div>
                    <div><?php echo $request['finance_approved_by_fullname'] ?? 'Pending'; ?></div>
                    <div><?php echo $request['finance_approved_at'] ? date('F d, Y', strtotime($request['finance_approved_at'])) : 'Pending'; ?></div>
                </div>
                
                <div style="clear: both;"></div>
            </div>
            
            <div class="footer">
                <p>This is a computer generated document. No physical signature is required.</p>
                <p>Confidential Document - For Internal Use Only</p>
                <p>Victory Financial Services Ltd | <?php echo date('Y'); ?></p>
            </div>
            
            <script>
                // Auto-print the document
                window.onload = function() {
                    window.print();
                };
            </script>
        </body>
        </html>
        <?php
        
    } catch (PDOException $e) {
        die('Error generating PDF: ' . $e->getMessage());
    }
} else {
    die('No request ID provided');
}
?>