<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_ceo();

require_admin();
require_trader();

require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle merge accounts action (This part is already efficient and does not need changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_accounts'])) {
    $primary_client_id = (int)$_POST['primary_client_id'];
    $merge_client_ids = $_POST['merge_client_ids'] ?? [];
    
    if ($primary_client_id && !empty($merge_client_ids)) {
        $db->beginTransaction();
        
        try {
            // Get primary client details
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$primary_client_id]);
            $primary_client = $stmt->fetch();
            
            if (!$primary_client) {
                throw new Exception("Primary client not found");
            }
            
            foreach ($merge_client_ids as $merge_id) {
                $merge_id = (int)$merge_id;
                if ($merge_id === $primary_client_id) continue;
                
                // Get merge client details
                $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
                $stmt->execute([$merge_id]);
                $merge_client = $stmt->fetch();
                
                if ($merge_client) {
                    $stmt = $db->prepare("UPDATE trades SET client_cds_account = ?, client_name = ? WHERE client_cds_account = ?");
                    $stmt->execute([$primary_client['cds_account'], $primary_client['client_name'], $merge_client['cds_account']]);
                    
                    $stmt = $db->prepare("UPDATE trades SET counterparty_cds_account = ?, counterparty_name = ? WHERE counterparty_cds_account = ?");
                    $stmt->execute([$primary_client['cds_account'], $primary_client['client_name'], $merge_client['cds_account']]);
                    
                    $stmt = $db->prepare("UPDATE trade_receipts SET client_cds_account = ?, client_name = ? WHERE client_cds_account = ?");
                    $stmt->execute([$primary_client['cds_account'], $primary_client['client_name'], $merge_client['cds_account']]);
                    
                    $stmt = $db->prepare("UPDATE trade_invoices SET client_cds_account = ?, client_name = ? WHERE client_cds_account = ?");
                    $stmt->execute([$primary_client['cds_account'], $primary_client['client_name'], $merge_client['cds_account']]);
                    
                    $stmt = $db->prepare("INSERT INTO client_merge_log (primary_client_id, merged_client_id, merged_cds_account, merged_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$primary_client_id, $merge_id, $merge_client['cds_account'], $_SESSION['user_id']]);
                    
                    $stmt = $db->prepare("UPDATE clients SET is_active = 0, merged_into = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$primary_client_id, $merge_id]);
                }
            }
            
            $db->commit();
            $success_message = "Successfully merged " . count($merge_client_ids) . " client accounts into primary account.";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error merging accounts: " . $e->getMessage();
        }
    }
}

// Handle add client action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_client'])) {
    $client_name = trim($_POST['client_name']);
    $cds_account = trim($_POST['cds_account']);
    $client_type = $_POST['client_type'];
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);

    // Basic validation
    if (empty($client_name) || empty($cds_account) || empty($client_type)) {
        $error_message = "Client Name, CDS Account, and Client Type are required fields.";
    } else {
        try {
            // Check if CDS account already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE cds_account = ? AND is_active = 1");
            $stmt->execute([$cds_account]);
            if ($stmt->fetchColumn() > 0) {
                $error_message = "A client with this CDS account already exists.";
            } else {
                $stmt = $db->prepare("INSERT INTO clients (client_name, cds_account, client_type, phone, email, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$client_name, $cds_account, $client_type, $phone, $email, $_SESSION['user_id']]);
                $success_message = "New client '$client_name' added successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error adding client: " . $e->getMessage();
        }
    }
}

// --- Pagination and Filter Logic ---
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start_from = ($current_page - 1) * $records_per_page;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Prepare the WHERE clause for filtering
$where_clause = 'WHERE c.is_active = 1';
$params = [];
if (!empty($search_query)) {
    $where_clause .= ' AND (c.client_name LIKE ? OR c.cds_account LIKE ?)';
    $like_search = '%' . $search_query . '%';
    $params[] = $like_search;
    $params[] = $like_search;
}

// Get the total number of clients for pagination (fast query)
$total_sql = "SELECT COUNT(*) FROM clients c " . $where_clause;
$total_stmt = $db->prepare($total_sql);
$total_stmt->execute($params);
$total_records = $total_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// --- START OF MAJOR OPTIMIZATION ---
// This new query is much faster because it uses a single JOIN and GROUP BY
// to get all the trade counts, instead of running subqueries for every single row.
// The new indexes you added will make this query even faster.
$all_clients = [];
$sql = "
    SELECT
        c.*,
        COUNT(t.id) as trade_count,
        SUM(CASE WHEN t.asset_class = 'bond' THEN 1 ELSE 0 END) as bond_count,
        SUM(CASE WHEN t.asset_class = 'equity' THEN 1 ELSE 0 END) as equity_count
    FROM clients c
    LEFT JOIN trades t ON c.cds_account = t.client_cds_account
    $where_clause
    GROUP BY c.id
    ORDER BY c.client_name
    LIMIT " . (int)$start_from . ", " . (int)$records_per_page;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$all_clients = $stmt->fetchAll();
// --- END OF MAJOR OPTIMIZATION ---

// The code for detecting duplicate clients has been removed from the page load.
// This was the source of the slowness, as it was a computationally expensive query
// that's not suited for a real-time web page.

include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, #3b82f6 100%);">
                            <i class="bi bi-people text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Client Account Management</h1>
                        <p class="page-subtitle">Manage client accounts and merge duplicate profiles</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
                        <i class="bi bi-person-plus me-2"></i>
                        Add Client
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if ($success_message): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2 text-success"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- All Clients Table -->
    <div class="card dashboard-card">
        <div class="card-header bg-transparent border-0">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="me-2">
                        <i class="bi bi-people text-primary"></i>
                    </div>
                    <h6 class="mb-0 fw-semibold">All Client Accounts</h6>
                </div>
                <span class="badge bg-primary"><?php echo $total_records; ?> clients</span>
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Search/Filter form -->
            <div class="p-3 border-bottom">
                <form method="GET" action="" class="d-flex">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Search by name or CDS account..." value="<?php echo htmlspecialchars($search_query); ?>">
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="clientsTable">
                    <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                        <tr>
                            <th class="border-0 fw-semibold text-dark py-3">Client Name</th>
                            <th class="border-0 fw-semibold text-dark py-3">CDS Account</th>
                            <th class="border-0 fw-semibold text-dark py-3">Contact Info</th>
                            <th class="border-0 fw-semibold text-dark py-3">Trade Activity</th>
                            <th class="border-0 fw-semibold text-dark py-3">Portfolio</th>
                            <th class="border-0 fw-semibold text-dark py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_clients)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">No clients found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_clients as $client): ?>
                            <tr>
                                <td class="border-0 py-3">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($client['client_name']); ?></div>
                                    <small class="text-muted"><?php echo ucfirst($client['client_type']); ?> Client</small>
                                </td>
                                <td class="border-0 py-3">
                                    <span class="badge bg-primary px-3 py-2"><?php echo htmlspecialchars($client['cds_account']); ?></span>
                                </td>
                                <td class="border-0 py-3">
                                    <div><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></small>
                                </td>
                                <td class="border-0 py-3">
                                    <span class="badge bg-success px-3 py-2"><?php echo $client['trade_count']; ?> trades</span>
                                </td>
                                <td class="border-0 py-3">
                                    <div class="d-flex gap-1">
                                        <?php if ($client['bond_count'] > 0): ?>
                                            <span class="badge bg-info"><?php echo $client['bond_count']; ?> bonds</span>
                                        <?php endif; ?>
                                        <?php if ($client['equity_count'] > 0): ?>
                                            <span class="badge bg-warning"><?php echo $client['equity_count']; ?> equities</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="border-0 py-3">
                                    <div class="btn-group">
                                        <a href="client_profile.php?id=<?php echo htmlspecialchars($client['id']); ?>" class="btn btn-outline-secondary btn-sm" title="View Profile">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button class="btn btn-outline-primary btn-sm" title="Edit Client">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination links -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-transparent py-3">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <?php if ($current_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="addClientModalLabel">Add New Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="client_name" class="form-label">Client Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="client_name" name="client_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="cds_account" class="form-label">CDS Account <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="cds_account" name="cds_account" required>
                    </div>
                    <div class="mb-3">
                        <label for="client_type" class="form-label">Client Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="client_type" name="client_type" required>
                            <option value="" selected disabled>Select client type</option>
                            <option value="individual">Individual</option>
                            <option value="corporate">Corporate</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="tel" class="form-control" id="phone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_client" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Add Client
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Merge Accounts Modal -->
<div class="modal fade" id="mergeAccountsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Merge Client Accounts</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action will merge all selected accounts into the primary account. 
                        All trades, receipts, and invoices will be transferred. This action cannot be undone.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Primary Account (Keep this account)</label>
                        <div id="primaryAccountInfo" class="p-3 bg-light rounded">
                            <!-- Primary account info will be populated by JavaScript -->
                        </div>
                        <input type="hidden" id="primary_client_id" name="primary_client_id">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Accounts to Merge (These will be deactivated)</label>
                        <div id="mergeAccountsList">
                            <!-- Merge accounts will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="merge_accounts" class="btn btn-danger">
                        <i class="bi bi-arrow-down-up me-1"></i>
                        Merge Accounts
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showMergeModal(primaryId, primaryName, primaryCds) {
    document.getElementById('primary_client_id').value = primaryId;
    document.getElementById('primaryAccountInfo').innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi bi-person-check text-success me-2"></i>
            <div>
                <div class="fw-semibold">${primaryName}</div>
                <small class="text-muted">CDS: ${primaryCds}</small>
            </div>
        </div>
    `;
    
    // Get similar clients for merging
    fetch(`get_similar_clients.php?client_id=${primaryId}`)
        .then(response => response.json())
        .then(data => {
            let html = '';
            data.forEach(client => {
                html += `
                    <div class="form-check p-3 border rounded mb-2">
                        <input class="form-check-input" type="checkbox" name="merge_client_ids[]" value="${client.id}" id="merge_${client.id}">
                        <label class="form-check-label w-100" for="merge_${client.id}">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold">${client.client_name}</div>
                                    <small class="text-muted">CDS: ${client.cds_account}</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-info">${client.trade_count} trades</span>
                                </div>
                            </div>
                        </label>
                    </div>
                `;
            });
            document.getElementById('mergeAccountsList').innerHTML = html;
        });
    
    new bootstrap.Modal(document.getElementById('mergeAccountsModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
