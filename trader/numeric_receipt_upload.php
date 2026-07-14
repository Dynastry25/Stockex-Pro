<?php
// ============================================
// SIMPLE DEBUG VERSION - UPLOAD TEST
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

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
// SIMPLE UPLOAD TEST
// ============================================
if (isset($_POST['test_upload']) && isset($_FILES['test_file'])) {
    $upload_dir = __DIR__ . '/../uploads/numeric_receipts/';
    
    // Create directory
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
        echo "Directory created: $upload_dir<br>";
    }
    
    echo "<h3>Upload Debug Info:</h3>";
    echo "Upload directory: $upload_dir<br>";
    echo "Directory writable: " . (is_writable($upload_dir) ? 'YES' : 'NO') . "<br>";
    echo "File name: " . $_FILES['test_file']['name'] . "<br>";
    echo "File size: " . $_FILES['test_file']['size'] . "<br>";
    echo "File error: " . $_FILES['test_file']['error'] . "<br>";
    echo "Temp file: " . $_FILES['test_file']['tmp_name'] . "<br>";
    
    if ($_FILES['test_file']['error'] === UPLOAD_ERR_OK) {
        $filename = 'test_' . date('Ymd_His') . '_' . $_FILES['test_file']['name'];
        $filepath = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['test_file']['tmp_name'], $filepath)) {
            echo "✅ File uploaded successfully: $filename<br>";
            echo "File path: $filepath<br>";
        } else {
            echo "❌ Failed to move uploaded file<br>";
        }
    } else {
        echo "❌ Upload error: " . $_FILES['test_file']['error'] . "<br>";
    }
    exit;
}

// ============================================
// NORMAL PAGE
// ============================================
$page_title = 'Numeric Reference Receipt Upload';
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2>Numeric Reference Receipt Upload</h2>
        </div>
    </div>

    <!-- SIMPLE UPLOAD TEST FORM -->
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="mb-0">🔧 Upload Test</h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="test_upload" value="1">
                <div class="row">
                    <div class="col-md-6">
                        <input type="file" class="form-control" name="test_file" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">Test Upload</button>
                    </div>
                </div>
                <div class="form-text">Select any file to test if upload is working</div>
            </form>
        </div>
    </div>

    <?php
    // ============================================
    // FETCH TRADES
    // ============================================
    try {
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
                ANY_VALUE(tr.payment_receipt) as payment_receipt,
                ANY_VALUE(tr.is_approved) as is_approved,
                ANY_VALUE(tr.approved_by) as approved_by
            FROM trades t
            LEFT JOIN numeric_trade_receipts tr ON t.id = tr.trade_id AND tr.trade_type = 'trade'
            WHERE t.additional_reference REGEXP '^[0-9]+$'
            AND t.additional_reference IS NOT NULL
            AND t.additional_reference != ''
            AND (
                (t.asset_class = 'bond') OR 
                (t.asset_class IN ('equity', 'Exchange Traded Funds') AND LOWER(t.trade_side) = 'buy')
            )
            GROUP BY t.id
            ORDER BY t.trade_date DESC
            LIMIT 20
        ";
        
        $trades = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error loading trades: ' . $e->getMessage() . '</div>';
        $trades = [];
    }
    ?>

    <!-- Trades Table -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">Trades with Numeric Reference (<?php echo count($trades); ?>)</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trades)): ?>
                <div class="text-center py-5">
                    <p class="text-muted">No trades found with numeric Additional Reference.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ref</th>
                                <th>Client</th>
                                <th>Security</th>
                                <th>Asset</th>
                                <th>Side</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Value</th>
                                <th>Date</th>
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
                                    <td><?php echo date('d/m/Y', strtotime($trade['trade_date'] ?? '')); ?></td>
                                    <td><code class="small"><?php echo htmlspecialchars($trade['additional_reference'] ?? ''); ?></code></td>
                                    <td>
                                        <?php if ($hasReceipt): ?>
                                            <span class="badge bg-success">Has Receipt</span>
                                        <?php else: ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="openUploadModal(<?php echo $trade['id']; ?>)">
                                                <i class="bi bi-upload"></i> Upload
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="openCommentModal(<?php echo $trade['id']; ?>)">
                                            <i class="bi bi-chat"></i>
                                        </button>
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

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="numeric_receipt_upload.php" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="upload_trade_id" value="">
                    <input type="hidden" name="upload_receipt" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($_GET['filter'] ?? 'pending'); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($_GET['asset_class'] ?? 'all'); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Receipt Files</label>
                        <input type="file" class="form-control" name="payment_receipts[]" accept="image/*,.pdf" multiple required>
                        <div class="form-text">Allowed: JPG, PNG, GIF, PDF (Max 5MB each)</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Upload clear copies of payment receipts or confirmations.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="uploadBtn">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat"></i> Add Comment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="numeric_receipt_upload.php">
                <div class="modal-body">
                    <input type="hidden" name="trade_id" id="comment_trade_id" value="">
                    <input type="hidden" name="add_comment" value="1">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($_GET['filter'] ?? 'pending'); ?>">
                    <input type="hidden" name="asset_class" value="<?php echo htmlspecialchars($_GET['asset_class'] ?? 'all'); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Your Comment</label>
                        <textarea class="form-control" name="trader_comment" rows="4" placeholder="Enter your comment..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Comment</button>
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

function openCommentModal(tradeId) {
    document.getElementById('comment_trade_id').value = tradeId;
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}

// Handle form submission with loading state
document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
    const fileInput = this.querySelector('input[type="file"]');
    if (fileInput.files.length === 0) {
        e.preventDefault();
        alert('Please select at least one file.');
        return false;
    }
    
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
});
</script>

<?php include '../includes/footer.php'; ?>
