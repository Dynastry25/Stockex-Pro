<?php
// Include necessary files for database connection, authentication, and helper functions
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Ensure the user is logged in and is CEO
require_login();
require_ceo(); // Add this function to auth_middleware.php if not exists

// Establish database connection
$db = getDBConnection();

// --- Fetch Dashboard Data ---

// 1. Total Trade Value (for both BUY and SELL trades)
$total_trade_value_query = "SELECT SUM(consideration) AS total_value FROM trades";
$stmt = $db->query($total_trade_value_query);
$total_trade_value = $stmt->fetchColumn();

// 2. Total Number of Trades
$total_trades_query = "SELECT COUNT(*) AS total_trades FROM trades";
$stmt = $db->query($total_trades_query);
$total_trades = $stmt->fetchColumn();

// 3. Number of Unique Clients
$unique_clients_query = "SELECT COUNT(DISTINCT client_cds_account) AS unique_clients FROM trades";
$stmt = $db->query($unique_clients_query);
$unique_clients = $stmt->fetchColumn();

// 4. Number of Pending Payment Requests for CEO Approval
$pending_payments_query = "
    SELECT COUNT(*) as pending_count 
    FROM pending_pay 
    WHERE status = 'pending' AND ceo_approved_at IS NULL
";
$stmt = $db->query($pending_payments_query);
$pending_payments = $stmt->fetchColumn();

// 5. Pending Payment Requests for CEO Approval (max 5)
$pending_requests_query = "
    SELECT pp.*, 
           u.full_name as requested_by_name,
           lt.description as pay_to_desc
    FROM pending_pay pp
    LEFT JOIN users u ON pp.requested_by = u.id
    LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
    WHERE pp.status = 'pending' 
    AND pp.ceo_approved_at IS NULL
    ORDER BY pp.requested_at ASC
    LIMIT 5
";
$stmt = $db->query($pending_requests_query);
$pending_requests = $stmt->fetchAll();

// 6. Total Payment Requests (for CEO)
$total_payment_requests_query = "
    SELECT COUNT(*) as total_payments 
    FROM pending_pay 
    WHERE ceo_approved_at IS NOT NULL OR status = 'pending'
";
$stmt = $db->query($total_payment_requests_query);
$total_payment_requests = $stmt->fetchColumn();

// 7. Recent Trades (last 10)
$recent_trades_query = "
    SELECT *
    FROM trades
    ORDER BY trade_date DESC, id DESC
    LIMIT 10
";
$stmt = $db->query($recent_trades_query);
$recent_trades = $stmt->fetchAll();

// 8. Monthly Trade Value Data for Chart
$monthly_value_query = "
    SELECT
        DATE_FORMAT(trade_date, '%Y-%m') AS month,
        SUM(consideration) AS monthly_total
    FROM trades
    GROUP BY month
    ORDER BY month ASC
    LIMIT 12
";
$stmt = $db->query($monthly_value_query);
$monthly_data = $stmt->fetchAll();

$months = array_column($monthly_data, 'month');
$monthly_totals = array_column($monthly_data, 'monthly_total');

// Handle AJAX requests for payment approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $action = $_POST['action'];
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if (!$request_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get user ID (CEO)
        $user_id = $_SESSION['user_id'] ?? 1;
        
        if ($action === 'approve') {
            $notes = $_POST['notes'] ?? '';
            
            $stmt = $db->prepare("
                UPDATE pending_pay 
                SET ceo_approved_at = NOW(), 
                    ceo_approved_by = ?,
                    ceo_approval_notes = ?,
                    status = 'approved_ceo'
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$user_id, $notes, $request_id]);
            
            // Log approval in audit trail
            $audit_stmt = $db->prepare("
                INSERT INTO audit_trail (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'ceo_approval', ?, ?, ?)
            ");
            $audit_stmt->execute([
                $user_id,
                "CEO approved payment request #$request_id",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment request approved successfully']);
            
        } elseif ($action === 'reject') {
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            
            if (empty($rejection_reason)) {
                echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
                exit;
            }
            
            $stmt = $db->prepare("
                UPDATE pending_pay 
                SET ceo_approved_at = NOW(), 
                    ceo_approved_by = ?,
                    ceo_approval_notes = ?,
                    rejection_reason = ?,
                    status = 'rejected'
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$user_id, 'Rejected by CEO', $rejection_reason, $request_id]);
            
            // Log rejection in audit trail
            $audit_stmt = $db->prepare("
                INSERT INTO audit_trail (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'ceo_rejection', ?, ?, ?)
            ");
            $audit_stmt->execute([
                $user_id,
                "CEO rejected payment request #$request_id",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment request rejected successfully']);
            
        } elseif ($action === 'get_request_details') {
            // Get detailed request information
            $stmt = $db->prepare("
                SELECT pp.*, 
                       u.username as requested_by_username,
                       u.full_name as requested_by_fullname,
                       lt.description as pay_to_desc
                FROM pending_pay pp
                LEFT JOIN users u ON pp.requested_by = u.id
                LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
                WHERE pp.id = ?
            ");
            $stmt->execute([$request_id]);
            $request_details = $stmt->fetch();
            
            if ($request_details) {
                echo json_encode(['success' => true, 'data' => $request_details]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Payment approval error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'CEO Dashboard';
include '../includes/header.php';
?>

<!-- Include Chart.js library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container-fluid py-5">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">
                <i class="bi bi-speedometer2"></i> CEO Dashboard
            </h1>
        </div>
    </div>
    
    <!-- --- KPI Cards --- -->
    <div class="row g-4 mb-5">
        <div class="col-lg-3 col-md-6">
            <div class="card bg-primary text-white h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Total Trade Value</h5>
                        <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text">Tzs<?php echo format_currency($total_trade_value ?? 0, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card bg-info text-white h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Total Trades</h5>
                        <i class="bi bi-clipboard-data-fill fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text"><?php echo number_format($total_trades ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card bg-success text-white h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Unique Clients</h5>
                        <i class="bi bi-people-fill fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text"><?php echo number_format($unique_clients ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card bg-warning text-dark h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title fw-bold">Pending Approvals</h5>
                        <i class="bi bi-hourglass-split fs-1 opacity-50"></i>
                    </div>
                    <h2 class="card-text"><?php echo number_format($pending_payments ?? 0); ?></h2>
                    <small class="opacity-75">Payment requests awaiting your approval</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- --- Main Content (Chart & Pending Requests) --- -->
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">Monthly Trade Value Trend</h5>
                </div>
                <div class="card-body">
                    <canvas id="monthlyTradeChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Pending Payment Requests</h5>
                    <?php if ($pending_payments > 5): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                data-bs-toggle="modal" data-bs-target="#allRequestsModal">
                            View All (<?php echo $pending_payments; ?>)
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Request #</th>
                                    <th>Subject</th>
                                    <th>Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pending_requests)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="bi bi-check-circle display-6 text-success"></i>
                                            <p class="mt-2">No pending payment requests</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pending_requests as $request): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['request_no']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('M d', strtotime($request['requested_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="text-truncate" style="max-width: 150px;">
                                                    <?php echo htmlspecialchars($request['subject']); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($request['pay_to_desc'] ?? 'N/A'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong class="text-success">
                                                    <?php echo number_format($request['amount_paid'], 2); ?>
                                                    <?php echo htmlspecialchars($request['currency']); ?>
                                                </strong>
                                                <br>
                                                <small class="text-muted">
                                                    by <?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-info view-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success approve-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger reject-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
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
    </div>

    <!-- --- Recent Trades Table --- -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm rounded-4">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">Recent Trades</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Trade #</th>
                                    <th>Client</th>
                                    <th>Security</th>
                                    <th>Side</th>
                                    <th>Value</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_trades)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No recent trades.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_trades as $trade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trade['trade_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['client_name']); ?></td>
                                            <td><?php echo htmlspecialchars($trade['security_name']); ?></td>
                                            <td>
                                                <span class="badge <?php echo ($trade['trade_side'] == 'BUY') ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo htmlspecialchars($trade['trade_side']); ?>
                                                </span>
                                            </td>
                                            <td>Tzs<?php echo format_currency($trade['trade_value'] ?? 0); ?></td>
                                            <td><?php echo format_date($trade['trade_date']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- All Pending Requests Modal -->
<div class="modal fade" id="allRequestsModal" tabindex="-1" aria-labelledby="allRequestsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="allRequestsModalLabel">
                    <i class="bi bi-list-check me-2"></i>All Pending Payment Requests (<?php echo $pending_payments; ?>)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="allRequestsTable">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Date</th>
                                <th>Subject</th>
                                <th>Pay To</th>
                                <th>Requested By</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch all pending requests
                            $all_pending_query = "
                                SELECT pp.*, 
                                       u.full_name as requested_by_name,
                                       lt.description as pay_to_desc
                                FROM pending_pay pp
                                LEFT JOIN users u ON pp.requested_by = u.id
                                LEFT JOIN ledger_types lt ON pp.pay_to_type = lt.code
                                WHERE pp.status = 'pending' 
                                AND pp.ceo_approved_at IS NULL
                                ORDER BY pp.requested_at ASC
                            ";
                            $stmt = $db->query($all_pending_query);
                            $all_requests = $stmt->fetchAll();
                            ?>
                            
                            <?php if (empty($all_requests)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No pending payment requests
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_requests as $request): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($request['request_no']); ?></strong>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($request['requested_at'])); ?></td>
                                        <td>
                                            <div class="text-truncate" style="max-width: 200px;">
                                                <?php echo htmlspecialchars($request['subject']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($request['pay_to_desc'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?></td>
                                        <td class="fw-bold text-success">
                                            <?php echo number_format($request['amount_paid'], 2); ?>
                                            <?php echo htmlspecialchars($request['currency']); ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-info view-request" 
                                                        data-request-id="<?php echo (int)$request['id']; ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                                <button type="button" class="btn btn-outline-success approve-request" 
                                                        data-request-id="<?php echo (int)$request['id']; ?>">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-outline-danger reject-request" 
                                                        data-request-id="<?php echo (int)$request['id']; ?>">
                                                    <i class="bi bi-x-lg"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- View Request Details Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewRequestModalLabel">
                    <i class="bi bi-receipt me-2"></i>Payment Request Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="requestDetailsContent">
                <!-- Request details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="modalApproveBtn" style="display: none;">
                    <i class="bi bi-check-lg me-1"></i>Approve
                </button>
                <button type="button" class="btn btn-danger" id="modalRejectBtn" style="display: none;">
                    <i class="bi bi-x-lg me-1"></i>Reject
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Request Modal -->
<div class="modal fade" id="approveRequestModal" tabindex="-1" aria-labelledby="approveRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="approveRequestModalLabel">
                    <i class="bi bi-check-circle me-2"></i>Approve Payment Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="approveRequestForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="request_id" id="approveRequestId">
                    <input type="hidden" name="action" value="approve">
                    
                    <div class="mb-3">
                        <p>Are you sure you want to approve this payment request?</p>
                        <div id="approveRequestSummary"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="approveNotes" class="form-label">Approval Notes (Optional)</label>
                        <textarea class="form-control" id="approveNotes" name="notes" rows="3" 
                                  placeholder="Add any notes or comments for this approval..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Request Modal -->
<div class="modal fade" id="rejectRequestModal" tabindex="-1" aria-labelledby="rejectRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rejectRequestModalLabel">
                    <i class="bi bi-x-circle me-2"></i>Reject Payment Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="rejectRequestForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="request_id" id="rejectRequestId">
                    <input type="hidden" name="action" value="reject">
                    
                    <div class="mb-3">
                        <p>Are you sure you want to reject this payment request?</p>
                        <div id="rejectRequestSummary"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejectionReason" name="rejection_reason" rows="3" 
                                  placeholder="Please provide a reason for rejection..." required></textarea>
                        <div class="invalid-feedback">Please provide a rejection reason.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript for the Chart and Payment Request Handling -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Chart
    const ctx = document.getElementById('monthlyTradeChart').getContext('2d');
    const monthlyTradeChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Monthly Trade Value (Tzs)',
                data: <?php echo json_encode($monthly_totals); ?>,
                borderColor: 'rgba(75, 192, 192, 1)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Value (Tzs)'
                    },
                    ticks: {
                        callback: function(value, index, values) {
                            return 'Tzs' + (value / 1000) + 'k';
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });

    // Toast notification function
    function showToast(message, type = 'info') {
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }

        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-bg-${type} border-0`;
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        toastContainer.appendChild(toast);
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();

        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    // View Request Details
    document.querySelectorAll('.view-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            loadRequestDetails(requestId, true);
        });
    });

    // Load request details
    function loadRequestDetails(requestId, showActionButtons = false) {
        const formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('action', 'get_request_details');
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const request = data.data;
                const details = `
                    <div class="request-details">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-primary">Request Information</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold" style="width: 40%;">Request No:</td>
                                        <td><strong>${request.request_no}</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Subject:</td>
                                        <td>${request.subject}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Requested Date:</td>
                                        <td>${new Date(request.requested_at).toLocaleString()}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Requested By:</td>
                                        <td>${request.requested_by_fullname || request.requested_by_username || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Status:</td>
                                        <td>
                                            <span class="badge bg-warning">
                                                PENDING CEO APPROVAL
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">Payment Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold" style="width: 40%;">Pay To:</td>
                                        <td>${request.pay_to_desc || request.pay_to_type}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Payee Name:</td>
                                        <td>${request.payee_name}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Payee ID:</td>
                                        <td>${request.payee_id || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Amount:</td>
                                        <td class="fw-bold text-success">
                                            ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Cheque No:</td>
                                        <td>${request.cheque_no || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Description:</td>
                                        <td>${request.payment_description || '-'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-primary">Bank Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold" style="width: 40%;">Bank Name:</td>
                                        <td>${request.payee_bank_name || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Branch:</td>
                                        <td>${request.payee_branch || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Account Name:</td>
                                        <td>${request.payee_account_name || '-'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Account No:</td>
                                        <td>${request.payee_account_no}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                document.getElementById('requestDetailsContent').innerHTML = details;
                
                // Show/hide action buttons
                const approveBtn = document.getElementById('modalApproveBtn');
                const rejectBtn = document.getElementById('modalRejectBtn');
                
                if (showActionButtons && request.status === 'pending') {
                    approveBtn.style.display = 'inline-block';
                    rejectBtn.style.display = 'inline-block';
                    
                    // Set up approve button
                    approveBtn.onclick = function() {
                        showApproveModal(requestId, request);
                    };
                    
                    // Set up reject button
                    rejectBtn.onclick = function() {
                        showRejectModal(requestId, request);
                    };
                } else {
                    approveBtn.style.display = 'none';
                    rejectBtn.style.display = 'none';
                }
                
                // Show the modal
                const viewModal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
                viewModal.show();
            } else {
                showToast(data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error loading request:', error);
            showToast('Error loading request details', 'danger');
        });
    }

    // Show Approve Modal
    function showApproveModal(requestId, request) {
        document.getElementById('approveRequestId').value = requestId;
        document.getElementById('approveRequestSummary').innerHTML = `
            <div class="alert alert-info">
                <strong>${request.request_no}</strong> - ${request.subject}<br>
                <strong>Amount:</strong> ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}<br>
                <strong>Payee:</strong> ${request.payee_name}
            </div>
        `;
        
        const approveModal = new bootstrap.Modal(document.getElementById('approveRequestModal'));
        approveModal.show();
        
        // Close the view modal
        const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (viewModal) viewModal.hide();
    }

    // Show Reject Modal
    function showRejectModal(requestId, request) {
        document.getElementById('rejectRequestId').value = requestId;
        document.getElementById('rejectRequestSummary').innerHTML = `
            <div class="alert alert-warning">
                <strong>${request.request_no}</strong> - ${request.subject}<br>
                <strong>Amount:</strong> ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}<br>
                <strong>Payee:</strong> ${request.payee_name}
            </div>
        `;
        
        const rejectModal = new bootstrap.Modal(document.getElementById('rejectRequestModal'));
        rejectModal.show();
        
        // Close the view modal
        const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewRequestModal'));
        if (viewModal) viewModal.hide();
    }

    // Approve Request
    document.getElementById('approveRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Approving...';
        submitBtn.disabled = true;
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showToast(data.message, 'danger');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error approving request:', error);
            showToast('Error approving request', 'danger');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });

    // Reject Request
    document.getElementById('rejectRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!this.checkValidity()) {
            this.classList.add('was-validated');
            return;
        }
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Rejecting...';
        submitBtn.disabled = true;
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showToast(data.message, 'danger');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error rejecting request:', error);
            showToast('Error rejecting request', 'danger');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });

    // Approve request from table buttons
    document.querySelectorAll('.approve-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            // Load request details first
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action', 'get_request_details');
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showApproveModal(requestId, data.data);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error loading request:', error);
                showToast('Error loading request details', 'danger');
            });
        });
    });

    // Reject request from table buttons
    document.querySelectorAll('.reject-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            // Load request details first
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action', 'get_request_details');
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showRejectModal(requestId, data.data);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error loading request:', error);
                showToast('Error loading request details', 'danger');
            });
        });
    });
});
</script>

<style>
.toast-container {
    z-index: 9999;
}
.request-details table tr td {
    padding: 4px 8px;
}
.badge {
    font-size: 0.75em;
}
</style>

<?php include '../includes/footer.php'; ?>