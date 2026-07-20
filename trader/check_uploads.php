<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_trader();

$db = getDBConnection();

echo '<h2>Upload Check</h2>';

// Check upload directory
$upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
echo '<h3>1. Upload Directory</h3>';
echo 'Path: ' . $upload_dir . '<br>';
echo 'Exists: ' . (file_exists($upload_dir) ? '✅ Yes' : '❌ No') . '<br>';
echo 'Writable: ' . (is_writable($upload_dir) ? '✅ Yes' : '❌ No') . '<br>';

if (file_exists($upload_dir)) {
    echo '<h4>Files:</h4><ul>';
    $files = scandir($upload_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $filepath = $upload_dir . $file;
            echo '<li>' . $file . ' (' . number_format(filesize($filepath)) . ' bytes) - ' . date('Y-m-d H:i:s', filemtime($filepath)) . '</li>';
        }
    }
    echo '</ul>';
}

echo '<h3>2. Database Records</h3>';
try {
    $stmt = $db->prepare("SELECT * FROM numeric_trade_receipts ORDER BY id DESC LIMIT 10");
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        echo '<p class="text-muted">No records found.</p>';
    } else {
        echo '<table border="1" cellpadding="5">';
        echo '<tr><th>ID</th><th>Trade ID</th><th>Receipts</th><th>Uploaded By</th><th>Created At</th></tr>';
        foreach ($records as $record) {
            echo '<tr>';
            echo '<td>' . $record['id'] . '</td>';
            echo '<td>' . $record['trade_id'] . '</td>';
            echo '<td>' . htmlspecialchars($record['payment_receipt'] ?? '') . '</td>';
            echo '<td>' . ($record['uploaded_by'] ?? 'N/A') . '</td>';
            echo '<td>' . ($record['created_at'] ?? 'N/A') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

// Check trades with numeric references
echo '<h3>3. Trades with Numeric Additional Reference</h3>';
try {
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.trade_reference,
            t.client_name,
            t.additional_reference,
            tr.payment_receipt,
            tr.is_approved
        FROM trades t
        LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
        WHERE t.additional_reference REGEXP '^[0-9]+$'
        AND t.additional_reference IS NOT NULL
        AND t.additional_reference != ''
        ORDER BY t.trade_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($trades)) {
        echo '<p class="text-muted">No trades with numeric additional reference found.</p>';
    } else {
        echo '<table border="1" cellpadding="5">';
        echo '<tr><th>Trade Ref</th><th>Client</th><th>Additional Ref</th><th>Has Receipt</th><th>Status</th></tr>';
        foreach ($trades as $trade) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($trade['trade_reference']) . '</td>';
            echo '<td>' . htmlspecialchars($trade['client_name']) . '</td>';
            echo '<td>' . htmlspecialchars($trade['additional_reference']) . '</td>';
            echo '<td>' . (!empty($trade['payment_receipt']) ? '✅ Yes' : '❌ No') . '</td>';
            echo '<td>' . ($trade['is_approved'] == 1 ? 'Approved' : ($trade['is_approved'] == 2 ? 'Rejected' : 'Pending')) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
