<?php
/**
 * Journal Entries Page
 * Displays all journal entries with filtering and search capabilities
 */

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();

if (!$db) {
    die("Database connection failed. Please contact administrator.");
}

// Helper functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter values
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$account_filter = $_GET['account'] ?? '';
$reference_type_filter = $_GET['reference_type'] ?? '';
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Validate dates if provided
if ($start_date && !validateDate($start_date)) {
    $start_date = '';
}
if ($end_date && !validateDate($end_date)) {
    $end_date = '';
}

// Sanitize inputs
$account_filter = sanitizeInput($account_filter);
$reference_type_filter = sanitizeInput($reference_type_filter);
$search_term = sanitizeInput($search_term);

// Pagination
$per_page = 50;
$offset = ($page - 1) * $per_page;
if ($offset < 0) $offset = 0;

// Build main query
$query = "
    SELECT
        gl.id,
        gl.transaction_date,
        gl.account_id,
        gl.account_code,
        gl.account_name,
        gl.debit_amount,
        gl.credit_amount,
        gl.description,
        gl.reference_no,
        gl.reference_type,
        gl.entity_name,
        gl.entity_type,
        gl.currency,
        gl.created_by_username,
        gl.created_at,
        coa.account_type,
        coa.normal_balance,
        u.full_name as created_by_name
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    LEFT JOIN users u ON gl.created_by = u.id
    WHERE gl.status = 'active'
";

$params = [];

// Apply date filter
if ($start_date && $end_date) {
    $query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

// Apply account filter
if (!empty($account_filter)) {
    if (is_numeric($account_filter)) {
        $query .= " AND gl.account_id = ?";
        $params[] = $account_filter;
    } else {
        $query .= " AND gl.account_code = ?";
        $params[] = $account_filter;
    }
}

// Apply reference type filter
if (!empty($reference_type_filter)) {
    $valid_types = ['trade', 'fee', 'adjustment', 'investment', 'payment', 'receipt', 'invoice', 'journal', 'transfer', 'expense', 'income'];
    if (in_array($reference_type_filter, $valid_types)) {
        $query .= " AND gl.reference_type = ?";
        $params[] = $reference_type_filter;
    }
}

// Apply search filter
if (!empty($search_term)) {
    $query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ? OR gl.account_name LIKE ?)";
    $search_like = "%$search_term%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

// Get total count
$count_query = "
    SELECT COUNT(*) as total
    FROM general_ledger gl
    LEFT JOIN chart_of_accounts coa ON gl.account_id = coa.id
    LEFT JOIN users u ON gl.created_by = u.id
    WHERE gl.status = 'active'
";

$count_params = [];

// Apply same filters to count query
if ($start_date && $end_date) {
    $count_query .= " AND gl.transaction_date BETWEEN ? AND ?";
    $count_params[] = $start_date;
    $count_params[] = $end_date;
}

if (!empty($account_filter)) {
    if (is_numeric($account_filter)) {
        $count_query .= " AND gl.account_id = ?";
        $count_params[] = $account_filter;
    } else {
        $count_query .= " AND gl.account_code = ?";
        $count_params[] = $account_filter;
    }
}

if (!empty($reference_type_filter)) {
    $valid_types = ['trade', 'fee', 'adjustment', 'investment', 'payment', 'receipt', 'invoice', 'journal', 'transfer', 'expense', 'income'];
    if (in_array($reference_type_filter, $valid_types)) {
        $count_query .= " AND gl.reference_type = ?";
        $count_params[] = $reference_type_filter;
    }
}

if (!empty($search_term)) {
    $count_query .= " AND (gl.description LIKE ? OR gl.reference_no LIKE ? OR gl.account_name LIKE ?)";
    $count_params[] = $search_like;
    $count_params[] = $search_like;
    $count_params[] = $search_like;
}

$stmt = $db->prepare($count_query);
$stmt->execute($count_params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Execute main query
$query .= " ORDER BY gl.transaction_date DESC, gl.id DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get list of accounts for filter dropdown
try {
    $accounts_stmt = $db->query("
        SELECT DISTINCT id, account_code, account_name
        FROM chart_of_accounts
        WHERE status = 'active'
        ORDER BY account_code
    ");
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}

$page_title = 'Journal Entries';
include '../includes/header.php';
?>

<style>
/* Professional journal entries styling */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --sidebar-bg: #f8f9fa;
    --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    --hover-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
}

body {
    background-color: #f5f7fb;
    font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.main-header {
    background: var(--primary-gradient);
    color: white;
    border-radius: 0 0 20px 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.account-card {
    border: none;
    border-radius: 15px;
    box-shadow: var(--card-shadow);
    transition: all 0.3s ease;
    margin-bottom: 20px;
}

.account-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--hover-shadow);
}

.account-card .card-header {
    border-radius: 15px 15px 0 0 !important;
    border: none;
    padding: 20px;
}

.account-list-item {
    border: none;
    border-bottom: 1px solid #eee;
    padding: 15px;
    transition: all 0.2s ease;
}

.account-list-item:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}

.account-list-item.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.balance-badge {
    font-size: 0.8rem;
    padding: 4px 10px;
    border-radius: 20px;
}

.table-custom {
    border-collapse: separate;
    border-spacing: 0;
}

.table-custom thead th {
    background: #f8f9fa;
    border: none;
    padding: 15px;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #e9ecef;
}

.table-custom tbody td {
    padding: 15px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}

.table-custom tbody tr:hover {
    background-color: #f8f9fa;
}

.reference-badge {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 12px;
}

.stat-card {
    border: none;
    border-radius: 15px;
    padding: 20px;
    text-align: center;
    color: white;
    margin-bottom: 20px;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.stat-card i {
    font-size: 2rem;
    margin-bottom: 10px;
}

.filter-card {
    border: none;
    border-radius: 15px;
    box-shadow: var(--card-shadow);
}

.btn-gradient {
    background: var(--primary-gradient);
    border: none;
    color: white;
    transition: all 0.3s ease;
}

.btn-gradient:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    color: white;
}

.date-range-display {
    background: white;
    border-radius: 10px;
    padding: 15px;
    border-left: 4px solid #667eea;
    margin-bottom: 20px;
}

.sidebar-scroll {
    max-height: calc(100vh - 300px);
    overflow-y: auto;
}

.sidebar-scroll::-webkit-scrollbar {
    width: 6px;
}

.sidebar-scroll::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.sidebar-scroll::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.sidebar-scroll::-webkit-scrollbar-thumb:hover {
    background: #a1a1a1;
}

.category-header {
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 8px;
    margin: 10px 0;
    font-weight: 600;
    color: #495057;
    border-left: 4px solid;
}

.quick-stats {
    background: white;
    border-radius: 15px;
    padding: 20px;
    box-shadow: var(--card-shadow);
}

.ledger-entry-card {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 10px;
    background: white;
    transition: all 0.2s ease;
}

.ledger-entry-card:hover {
    border-color: #667eea;
    box-shadow: 0 4px 8px rgba(102, 126, 234, 0.1);
}

.transaction-detail-card {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    background: white;
}

.transaction-detail-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.amount-large {
    font-size: 1.25rem;
    font-weight: bold;
}

.no-transactions-state {
    padding: 80px 20px;
    text-align: center;
}

.no-transactions-state i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 20px;
}

.print-hide {
    display: block;
}

@media print {
    .print-hide {
        display: none !important;
    }

    .account-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}

.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.journal-entries-container {
    margin-top: 30px;
}

.journal-entries-card {
    border: none;
    border-radius: 15px;
    box-shadow: var(--card-shadow);
    margin-bottom: 30px;
}

.journal-entries-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px 15px 0 0;
    padding: 20px;
    font-weight: 600;
}

.filter-section {
    background: white;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: var(--card-shadow);
}

.pagination-container {
    margin-top: 25px;
    justify-content: center;
}

.amount-debit {
    color: #dc2626;
    font-weight: 600;
}

.amount-credit {
    color: #059669;
    font-weight: 600;
}

.reference-code {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.9em;
}
</style>

<div class="container-fluid py-3">
    <!-- Main Header -->
    <div class="main-header py-4 px-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-journal-text display-4"></i>
                    </div>
                    <div>
                        <h1 class="h2 mb-1 fw-bold">Journal Entries</h1>
                        <p class="mb-0 opacity-90">
                            <i class="bi bi-calendar3 me-1"></i>
                            <?php
                            if ($start_date && $end_date) {
                                echo htmlspecialchars(date('F d, Y', strtotime($start_date)) . ' to ' . date('F d, Y', strtotime($end_date)));
                            } else {
                                echo 'All Transactions';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="btn-group print-hide">
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="showDateModal()">
                        <i class="bi bi-calendar-range me-2"></i>Change Period
                    </button>
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>Print
                    </button>
                    <button class="btn btn-light btn-gradient shadow-sm" onclick="exportReport('excel')">
                        <i class="bi bi-file-excel me-2"></i>Excel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="form-horizontal">
            <h6 class="mb-3"><i class="bi bi-funnel"></i> Filters</h6>
            <div class="row g-3">
                <div class="col-md-2">
                    <label for="start_date" class="form-label fw-bold">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control"
                           value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-md-2">
                    <label for="end_date" class="form-label fw-bold">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control"
                           value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-md-2">
                    <label for="account" class="form-label fw-bold">Account</label>
                    <select id="account" name="account" class="form-select">
                        <option value="">All Accounts</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>"
                                <?php echo ($account_filter == $acc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="reference_type" class="form-label fw-bold">Reference Type</label>
                    <select id="reference_type" name="reference_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="trade" <?php echo ($reference_type_filter == 'trade') ? 'selected' : ''; ?>>Trade</option>
                        <option value="fee" <?php echo ($reference_type_filter == 'fee') ? 'selected' : ''; ?>>Fee</option>
                        <option value="payment" <?php echo ($reference_type_filter == 'payment') ? 'selected' : ''; ?>>Payment</option>
                        <option value="receipt" <?php echo ($reference_type_filter == 'receipt') ? 'selected' : ''; ?>>Receipt</option>
                        <option value="journal" <?php echo ($reference_type_filter == 'journal') ? 'selected' : ''; ?>>Journal</option>
                        <option value="transfer" <?php echo ($reference_type_filter == 'transfer') ? 'selected' : ''; ?>>Transfer</option>
                        <option value="adjustment" <?php echo ($reference_type_filter == 'adjustment') ? 'selected' : ''; ?>>Adjustment</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="search" class="form-label fw-bold">Search</label>
                    <input type="text" id="search" name="search" class="form-control"
                           placeholder="Description, Reference #, Account..."
                           value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-gradient w-100">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Results Card -->
    <div class="journal-entries-card card">
        <div class="card-header">
            <i class="bi bi-journal-text me-2"></i>
            Journal Entries
            <span class="badge bg-light text-dark ms-auto"><?php echo number_format($total_records); ?> entries</span>
        </div>

        <div class="card-body">
            <?php if (empty($entries)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p class="mt-3">No journal entries found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th style="width: 12%;">Date</th>
                                <th style="width: 12%;">Reference</th>
                                <th style="width: 15%;">Account</th>
                                <th style="width: 20%;">Description</th>
                                <th style="width: 10%;">Type</th>
                                <th style="width: 12%;" class="text-end">Debit</th>
                                <th style="width: 12%;" class="text-end">Credit</th>
                                <th style="width: 7%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($entry['transaction_date'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="reference-code"><?php echo htmlspecialchars($entry['reference_no']); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($entry['account_code']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($entry['account_name'], 0, 30)); ?></small>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($entry['description'], 0, 40)); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars(ucfirst($entry['reference_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($entry['debit_amount'] > 0): ?>
                                            <span class="amount-debit">
                                                <?php echo number_format($entry['debit_amount'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($entry['credit_amount'] > 0): ?>
                                            <span class="amount-credit">
                                                <?php echo number_format($entry['credit_amount'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="journal_entry_view.php?id=<?php echo $entry['id']; ?>"
                                           class="btn btn-sm btn-outline-primary" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-container">
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>
                            </li>
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </a>
                            </li>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1):
                            ?>
                                <li class="page-item"><span class="page-link">...</span></li>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <li class="page-item"><span class="page-link">...</span></li>
                            <?php endif; ?>

                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                    <i class="bi bi-chevron-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Date Modal -->
<div class="modal fade" id="dateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Date Range</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="dateForm" method="GET">
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="clearDates">
                            <label class="form-check-label" for="clearDates">
                                Show all transactions (clear date filter)
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="applyDateFilter()">Apply</button>
            </div>
        </div>
    </div>
</div>

<script>
// Date validation
document.querySelector('[name="end_date"]').addEventListener('change', function() {
    const fromDateInput = document.querySelector('[name="start_date"]');
    if (this.value && fromDateInput.value) {
        const fromDate = new Date(fromDateInput.value);
        const toDate = new Date(this.value);
        if (toDate < fromDate) {
            alert('End date cannot be before start date');
            this.value = fromDateInput.value;
        }
    }
});

// Additional date validation for modal
document.addEventListener('DOMContentLoaded', function() {
    const modalStartDate = document.querySelector('#dateModal [name="start_date"]');
    const modalEndDate = document.querySelector('#dateModal [name="end_date"]');

    if (modalStartDate && modalEndDate) {
        modalEndDate.addEventListener('change', function() {
            if (this.value && modalStartDate.value) {
                const fromDate = new Date(modalStartDate.value);
                const toDate = new Date(this.value);
                if (toDate < fromDate) {
                    alert('End date cannot be before start date');
                    this.value = modalStartDate.value;
                }
            }
        });
    }
});

// Export functions
function exportReport(format) {
    if (format !== 'excel') {
        alert('Only Excel export is supported');
        return;
    }

    const filters = {
        start_date: document.querySelector('[name="start_date"]').value,
        end_date: document.querySelector('[name="end_date"]').value,
        account: document.querySelector('[name="account"]').value,
        reference_type: document.querySelector('[name="reference_type"]').value,
        search: document.querySelector('[name="search"]').value
    };

    // Build query string
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value) params.append(key, value);
    }
    params.append('export', 'excel');

    // Open export in new tab
    window.open('journal_entries_export.php?' + params.toString(), '_blank');
}

function showDateModal() {
    const modal = new bootstrap.Modal(document.getElementById('dateModal'));
    modal.show();
}

function applyDateFilter() {
    const form = document.getElementById('dateForm');
    const clearDates = document.getElementById('clearDates').checked;

    if (clearDates) {
        window.location.href = 'journal_entries';
    } else {
        const startDate = form.start_date.value;
        const endDate = form.end_date.value;

        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('start_date', startDate);
        url.searchParams.set('end_date', endDate);
        url.searchParams.delete('page'); // Reset to page 1

        window.location.href = url.toString();
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Journal Entries page loaded');
    console.log('Total records: <?php echo $total_records; ?>');
});
</script>

<?php include '../includes/footer.php'; ?>
