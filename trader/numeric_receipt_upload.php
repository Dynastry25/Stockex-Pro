<?php
// ============================================
// SIMPLE UPLOAD - MINIMAL WORKING VERSION
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_trader();

$db = getDBConnection();
$current_user = get_logged_in_user() ?: get_session_user();
$user_name = $current_user['username'] ?? 'System';

// ============================================
// HANDLE UPLOAD - SIMPLE VERSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_receipt'])) {
    
    $trade_id = isset($_POST['trade_id']) ? (int) $_POST['trade_id'] : 0;
    
    if ($trade_id <= 0) {
        echo "Error: Invalid trade ID";
        exit;
    }
    
    $upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploaded_files = [];
    
    // Process file upload
    if (isset($_FILES['payment_receipts']) && !empty($_FILES['payment_receipts']['name'][0])) {
        $files = $_FILES['payment_receipts'];
        $total_files = count($files['name']);
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $max_size = 5 * 1024 * 1024;
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                if (in_array($file_ext, $allowed_exts) && $files['size'][$i] <= $max_size) {
                    $filename = 'receipt_' . $trade_id . '_' . date('Ymd_His') . '_' . ($i + 1) . '.' . $file_ext;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                        $uploaded_files[] = $filename;
                        echo "✅ Uploaded: $filename<br>";
                    } else {
                        echo "❌ Failed to move file: " . $files['name'][$i] . "<br>";
                    }
                } else {
                    echo "❌ Invalid file type or size: " . $files['name'][$i] . "<br>";
                }
            } else {
                echo "❌ Upload error: " . $files['error'][$i] . "<br>";
            }
        }
    }
    
    // Save to database
    if (!empty($uploaded_files)) {
        $receipts_str = implode(',', $uploaded_files);
        
        try {
            // Check if record exists
            $stmt = $db->prepare("SELECT id FROM numeric_trade_receipts WHERE trade_id = ? AND trade_type = 'trade'");
            $stmt->execute([$trade_id]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                // Update
                $stmt = $db->prepare("UPDATE numeric_trade_receipts SET payment_receipt = ?, uploaded_by = ?, updated_at = NOW() WHERE trade_id = ? AND trade_type = 'trade'");
                $result = $stmt->execute([$receipts_str, $user_name, $trade_id]);
                echo "✅ Updated database record<br>";
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO numeric_trade_receipts (trade_id, trade_type, payment_receipt, uploaded_by, created_at, updated_at) VALUES (?, 'trade', ?, ?, NOW(), NOW())");
                $result = $stmt->execute([$trade_id, $receipts_str, $user_name]);
                echo "✅ Inserted new database record<br>";
            }
            
            if ($result) {
                echo "✅ Database saved successfully!<br>";
            } else {
                $errorInfo = $stmt->errorInfo();
                echo "❌ Database error: " . $errorInfo[2] . "<br>";
            }
        } catch (Exception $e) {
            echo "❌ Exception: " . $e->getMessage() . "<br>";
        }
        
        // Show the uploaded files
        echo "<h3>Uploaded Files:</h3>";
        echo "<ul>";
        foreach ($uploaded_files as $file) {
            echo "<li><a href='../uploads/numeric_receipts/$file' target='_blank'>$file</a></li>";
        }
        echo "</ul>";
    } else {
        echo "❌ No files were uploaded successfully.";
    }
    
    echo "<br><a href='upload_simple.php'>← Back</a>";
    exit;
}

// ============================================
// DISPLAY TRADES
// ============================================
$filter = $_GET['filter'] ?? 'pending';
$asset_class_filter = $_GET['asset_class'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get trades with numeric reference
$sql = "
    SELECT 
        t.id,
        t.trade_reference,
        t.asset_class,
        t.security_id,
        t.client_name,
        t.trade_side,
        t.quantity,
        t.price,
        t.consideration,
        t.trade_date,
        t.additional_reference,
        tr.payment_receipt,
        tr.is_approved
    FROM trades t
    LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
    WHERE t.additional_reference REGEXP '^[0-9]+$'
    AND t.additional_reference IS NOT NULL
    AND t.additional_reference != ''
    AND ((t.asset_class = 'bond') OR (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy'))
";

if ($filter === 'pending') {
    $sql .= " AND (tr.is_approved IS NULL OR tr.is_approved = 0)";
} elseif ($filter === 'approved') {
    $sql .= " AND tr.is_approved = 1";
} elseif ($filter === 'rejected') {
    $sql .= " AND tr.is_approved = 2";
}

if (!empty($search)) {
    $sql .= " AND (t.client_name LIKE '%$search%' OR t.security_id LIKE '%$search%')";
}

$sql .= " ORDER BY t.trade_date DESC";

$trades = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Simple Upload Test';
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2>Simple Upload Test</h2>
            <p>Upload receipts for numeric reference trades</p>
            
            <?php if (isset($_SESSION['alert'])): ?>
                <div class="alert alert-<?php echo $_SESSION['alert'][1]; ?>">
                    <?php echo $_SESSION['alert'][0]; ?>
                </div>
                <?php unset($_SESSION['alert']); ?>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2">
                        <div class="col-md-3">
                            <select class="form-select" name="filter" onchange="this.form.submit()">
                                <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Apply</button>
                            <a href="upload_simple.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Trades Table -->
            <div class="card">
                <div class="card-header">
                    <h6>Numeric Reference Trades (<?php echo count($trades); ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($trades)): ?>
                        <div class="text-center py-4">No trades found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <th>Client</th>
                                        <th>Security</th>
                                        <th>Asset</th>
                                        <th>Side</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Price</th>
                                        <th class="text-end">Value</th>
                                        <th>Add Ref</th>
                                        <th>Receipt</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trades as $trade): 
                                        $isBond = ($trade['asset_class'] === 'bond');
                                        $hasReceipt = !empty($trade['payment_receipt']);
                                        $isApproved = isset($trade['is_approved']) ? (int)$trade['is_approved'] : 0;
                                        $statusText = $isApproved === 1 ? 'Approved' : ($isApproved === 2 ? 'Rejected' : 'Pending');
                                        $statusClass = $isApproved === 1 ? 'success' : ($isApproved === 2 ? 'danger' : 'warning');
                                        
                                        if ($isBond) {
                                            $displayQty = 'TZS ' . number_format(floatval($trade['quantity'] ?? 0), 2);
                                            $displayPrice = number_format(floatval($trade['price'] ?? 0), 4) . '%';
                                            $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                            $assetClass = 'Bond';
                                        } else {
                                            $displayQty = number_format(floatval($trade['quantity'] ?? 0), 0);
                                            $displayPrice = 'TZS ' . number_format(floatval($trade['price'] ?? 0), 2);
                                            $displayValue = 'TZS ' . number_format(floatval($trade['consideration'] ?? 0), 2);
                                            $assetClass = ucfirst($trade['asset_class'] ?? 'Equity');
                                            if ($assetClass === 'Exchange Traded Funds') $assetClass = 'ETF';
                                        }
                                    ?>
                                        <tr>
                                            <td><span class="fw-semibold small"><?php echo htmlspecialchars($trade['trade_reference'] ?? ''); ?></span></td>
                                            <td><?php echo htmlspecialchars($trade['client_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_id'] ?? ''); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo $assetClass; ?></span></td>
                                            <td><span class="badge <?php echo strtolower($trade['trade_side'] ?? '') === 'buy' ? 'bg-success' : 'bg-danger'; ?>"><?php echo strtoupper($trade['trade_side'] ?? ''); ?></span></td>
                                            <td class="text-end"><?php echo $displayQty; ?></td>
                                            <td class="text-end"><?php echo $displayPrice; ?></td>
                                            <td class="text-end fw-bold"><?php echo $displayValue; ?></td>
                                            <td><code><?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?></code></td>
                                            <td>
                                                <?php if ($hasReceipt): ?>
                                                    <span class="badge bg-success">✅ Has Receipt</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No Receipt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                            <td class="text-end">
                                                <?php if (!$hasReceipt || $isApproved !== 1): ?>
                                                    <button class="btn btn-primary btn-sm" onclick="openUploadModal(<?php echo $trade['id']; ?>)">
                                                        <i class="bi bi-upload"></i> Upload
                                                    </button>
                                                <?php endif; ?>
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
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="upload_simple.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="upload_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Receipt File</label>
                        <input type="file" class="form-control" name="payment_receipts[]" accept="image/*,.pdf" required>
                        <div class="form-text">JPG, PNG, GIF, PDF (Max 5MB)</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Upload a clear copy of the payment receipt.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openUploadModal(tradeId) {
    document.getElementById('upload_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
