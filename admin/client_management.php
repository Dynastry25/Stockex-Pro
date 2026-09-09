<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow CEO, Admin, and Trader access
if (!has_role('CEO') && !has_role('Admin') && !has_role('Trader')) {
    header('Location: ../auth/login.php');
    exit;
}

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
    $national_id = trim($_POST['national_id']);
    $date_of_birth = trim($_POST['date_of_birth']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $bank_account_number = trim($_POST['bank_account_number']);
    $bank_name = trim($_POST['bank_name']);
    $bank_branch = trim($_POST['bank_branch']);
    $currency = trim($_POST['currency']) ?: 'TZS';
    $client_code = trim($_POST['client_code']);
    $fee_type = trim($_POST['fee_type']) ?: 'normal';
    $default_brokerage_fee = !empty($_POST['default_brokerage_fee']) ? (float)$_POST['default_brokerage_fee'] : null;

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
                $stmt = $db->prepare("INSERT INTO clients 
                    (client_name, cds_account, client_type, national_id, date_of_birth, phone, email, 
                     address, bank_account_number, bank_name, bank_branch, currency, client_code, 
                     fee_type, default_brokerage_fee, status, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                
                $stmt->execute([
                    $client_name, $cds_account, $client_type, $national_id ?: null, 
                    $date_of_birth ?: null, $phone ?: null, $email ?: null, $address ?: null,
                    $bank_account_number ?: null, $bank_name ?: null, $bank_branch ?: null,
                    $currency, $client_code ?: null, $fee_type, $default_brokerage_fee, $_SESSION['user_id']
                ]);
                
                $success_message = "New client '$client_name' added successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error adding client: " . $e->getMessage();
        }
    }
}

// Handle edit client action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_client'])) {
    $client_id = (int)$_POST['client_id'];
    $client_name = trim($_POST['client_name']);
    $cds_account = trim($_POST['cds_account']);
    $client_type = $_POST['client_type'];
    $national_id = trim($_POST['national_id']);
    $date_of_birth = trim($_POST['date_of_birth']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $bank_account_number = trim($_POST['bank_account_number']);
    $bank_name = trim($_POST['bank_name']);
    $bank_branch = trim($_POST['bank_branch']);
    $currency = trim($_POST['currency']) ?: 'TZS';
    $client_code = trim($_POST['client_code']);
    $fee_type = trim($_POST['fee_type']) ?: 'normal';
    $default_brokerage_fee = !empty($_POST['default_brokerage_fee']) ? (float)$_POST['default_brokerage_fee'] : null;
    $status = $_POST['status'];

    // Basic validation
    if (empty($client_name) || empty($cds_account) || empty($client_type) || empty($status)) {
        $error_message = "Required fields are missing.";
    } else {
        try {
            // Check if CDS account already exists for another client
            $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE cds_account = ? AND id != ? AND is_active = 1");
            $stmt->execute([$cds_account, $client_id]);
            if ($stmt->fetchColumn() > 0) {
                $error_message = "A client with this CDS account already exists.";
            } else {
                $stmt = $db->prepare("UPDATE clients SET 
                    client_name = ?, cds_account = ?, client_type = ?, national_id = ?, date_of_birth = ?, 
                    phone = ?, email = ?, address = ?, bank_account_number = ?, bank_name = ?, 
                    bank_branch = ?, currency = ?, client_code = ?, fee_type = ?, 
                    default_brokerage_fee = ?, status = ?, updated_at = NOW() 
                    WHERE id = ?");
                
                $stmt->execute([
                    $client_name, $cds_account, $client_type, $national_id ?: null, 
                    $date_of_birth ?: null, $phone ?: null, $email ?: null, $address ?: null,
                    $bank_account_number ?: null, $bank_name ?: null, $bank_branch ?: null,
                    $currency, $client_code ?: null, $fee_type, $default_brokerage_fee, 
                    $status, $client_id
                ]);
                
                $success_message = "Client '$client_name' updated successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error updating client: " . $e->getMessage();
        }
    }
}

// Handle delete client action (soft delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    $client_id = (int)$_POST['client_id'];
    
    try {
        // Check if client has any active trades
        $stmt = $db->prepare("SELECT COUNT(*) FROM trades WHERE client_cds_account = (SELECT cds_account FROM clients WHERE id = ?) AND status = 'active'");
        $stmt->execute([$client_id]);
        $trade_count = $stmt->fetchColumn();
        
        if ($trade_count > 0) {
            $error_message = "Cannot delete client with active trades. Please deactivate the client instead.";
        } else {
            $stmt = $db->prepare("UPDATE clients SET is_active = 0, status = 'inactive', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$client_id]);
            
            if ($stmt->rowCount() > 0) {
                $success_message = "Client deleted successfully!";
            } else {
                $error_message = "Client not found.";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error deleting client: " . $e->getMessage();
    }
}

// --- Pagination and Filter Logic ---
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start_from = ($current_page - 1) * $records_per_page;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';

// Prepare the WHERE clause for filtering
$where_clause = 'WHERE c.is_active = 1';
$params = [];
if ($status_filter === 'inactive') {
    $where_clause = 'WHERE c.is_active = 0';
} elseif ($status_filter === 'all') {
    $where_clause = 'WHERE 1=1';
}

if (!empty($search_query)) {
    $where_clause .= (strpos($where_clause, 'WHERE') === false ? ' WHERE ' : ' AND ') . 
                     '(c.client_name LIKE ? OR c.cds_account LIKE ? OR c.national_id LIKE ? OR c.client_code LIKE ?)';
    $like_search = '%' . $search_query . '%';
    $params[] = $like_search;
    $params[] = $like_search;
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
        SUM(CASE WHEN t.asset_class = 'equity' THEN 1 ELSE 0 END) as equity_count,
        SUM(CASE WHEN t.asset_class = 'Exchange Traded Funds' THEN 1 ELSE 0 END) as etf_count
    FROM clients c
    LEFT JOIN trades t ON c.cds_account = t.client_cds_account AND t.status = 'active'
    $where_clause
    GROUP BY c.id
    ORDER BY c.client_name
    LIMIT " . (int)$start_from . ", " . (int)$records_per_page;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$all_clients = $stmt->fetchAll();
// --- END OF MAJOR OPTIMIZATION ---

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
                    <button type="button" class="btn btn-info text-white" onclick="loadSubmissions()">
                        <i class="bi bi-inbox me-2"></i>
                        Submissions
                    </button>
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
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="card dashboard-card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-8">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Search by name, CDS account, National ID, or Client Code..." value="<?php echo htmlspecialchars($search_query); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Clients</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive Clients</option>
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Clients</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

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
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="clientsTable">
                    <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                        <tr>
                            <th class="border-0 fw-semibold text-dark py-3">Client Name</th>
                            <th class="border-0 fw-semibold text-dark py-3">CDS Account</th>
                            <th class="border-0 fw-semibold text-dark py-3">Client Details</th>
                            <th class="border-0 fw-semibold text-dark py-3">Contact Info</th>
                            <th class="border-0 fw-semibold text-dark py-3">Trade Activity</th>
                            <th class="border-0 fw-semibold text-dark py-3">Portfolio</th>
                            <th class="border-0 fw-semibold text-dark py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_clients)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">No clients found.</td>
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
                                    <small class="d-block">
                                        <strong>ID:</strong> <?php echo htmlspecialchars($client['national_id'] ?? 'N/A'); ?>
                                    </small>
                                    <small class="d-block">
                                        <strong>DOB:</strong> <?php echo !empty($client['date_of_birth']) ? date('d/m/Y', strtotime($client['date_of_birth'])) : 'N/A'; ?>
                                    </small>
                                    <small class="d-block">
                                        <strong>Code:</strong> <?php echo htmlspecialchars($client['client_code'] ?? 'N/A'); ?>
                                    </small>
                                </td>
                                <td class="border-0 py-3">
                                    <div><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></small>
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            <?php if ($client['bank_account_number']): ?>
                                                Bank: <?php echo htmlspecialchars($client['bank_account_number']); ?>
                                            <?php else: ?>
                                                No bank account
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </td>
                                <td class="border-0 py-3">
                                    <span class="badge bg-success px-3 py-2"><?php echo $client['trade_count']; ?> trades</span>
                                </td>
                                <td class="border-0 py-3">
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if ($client['bond_count'] > 0): ?>
                                            <span class="badge bg-info"><?php echo $client['bond_count']; ?> bonds</span>
                                        <?php endif; ?>
                                        <?php if ($client['equity_count'] > 0): ?>
                                            <span class="badge bg-warning"><?php echo $client['equity_count']; ?> equities</span>
                                        <?php endif; ?>
                                        <?php if ($client['etf_count'] > 0): ?>
                                            <span class="badge bg-purple"><?php echo $client['etf_count']; ?> ETFs</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="border-0 py-3">
                                    <div class="btn-group">
                                        <a href="client_profile.php?id=<?php echo htmlspecialchars($client['id']); ?>" class="btn btn-outline-secondary btn-sm" title="View Profile">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button class="btn btn-outline-primary btn-sm" title="Edit Client" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($client)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-info btn-sm" title="Send Update Link - populate a secure link for the client to update their own details" onclick="showPortalLinkModal('<?php echo htmlspecialchars(addslashes($client['client_name'])); ?>', '<?php echo htmlspecialchars($client['cds_account']); ?>')">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                        <?php if (has_role('CEO') || has_role('Admin')): ?>
                                        <button class="btn btn-outline-danger btn-sm" title="Delete Client" onclick="confirmDelete(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars(addslashes($client['client_name'])); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
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
                                <a class="page-link" href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $status_filter !== 'active' ? '&status=' . urlencode($status_filter) : ''; ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $status_filter !== 'active' ? '&status=' . urlencode($status_filter) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $status_filter !== 'active' ? '&status=' . urlencode($status_filter) : ''; ?>" aria-label="Next">
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="addClientModalLabel">Add New Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="client_name" class="form-label">Client Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="client_name" name="client_name" required maxlength="200">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="cds_account" class="form-label">CDS Account <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="cds_account" name="cds_account" required maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="client_type" class="form-label">Client Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="client_type" name="client_type" required>
                                <option value="" selected disabled>Select client type</option>
                                <option value="individual">Individual</option>
                                <option value="corporate">Corporate</option>
                                <option value="institutional">Institutional</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="national_id" class="form-label">National ID</label>
                            <input type="text" class="form-control" id="national_id" name="national_id" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="client_code" class="form-label">Client Code</label>
                            <input type="text" class="form-control" id="client_code" name="client_code" maxlength="120">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone" maxlength="20">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="100">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2" maxlength="500"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="bank_account_number" class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="bank_account_number" name="bank_account_number" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="bank_name" name="bank_name" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="bank_branch" class="form-label">Bank Branch</label>
                            <input type="text" class="form-control" id="bank_branch" name="bank_branch" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="currency" class="form-label">Currency</label>
                            <select class="form-select" id="currency" name="currency">
                                <option value="TZS" selected>TZS - Tanzanian Shilling</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="fee_type" name="fee_type">
                                <option value="normal" selected>Normal</option>
                                <option value="liberty">Liberty</option>
                                <option value="this_trade">This Trade</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="default_brokerage_fee" class="form-label">Default Brokerage Fee (%)</label>
                            <input type="number" step="0.01" class="form-control" id="default_brokerage_fee" name="default_brokerage_fee">
                        </div>
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

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" id="edit_client_id" name="client_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editClientModalLabel">Edit Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_client_name" class="form-label">Client Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_client_name" name="client_name" required maxlength="200">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_cds_account" class="form-label">CDS Account <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_cds_account" name="cds_account" required maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_client_type" class="form-label">Client Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_client_type" name="client_type" required>
                                <option value="individual">Individual</option>
                                <option value="corporate">Corporate</option>
                                <option value="institutional">Institutional</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_national_id" class="form-label">National ID</label>
                            <input type="text" class="form-control" id="edit_national_id" name="national_id" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="edit_date_of_birth" name="date_of_birth">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_client_code" class="form-label">Client Code</label>
                            <input type="text" class="form-control" id="edit_client_code" name="client_code" maxlength="120">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone" maxlength="20">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" maxlength="100">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="edit_address" class="form-label">Address</label>
                            <textarea class="form-control" id="edit_address" name="address" rows="2" maxlength="500"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_bank_account_number" class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="edit_bank_account_number" name="bank_account_number" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="edit_bank_name" name="bank_name" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_bank_branch" class="form-label">Bank Branch</label>
                            <input type="text" class="form-control" id="edit_bank_branch" name="bank_branch" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_currency" class="form-label">Currency</label>
                            <select class="form-select" id="edit_currency" name="currency">
                                <option value="TZS">TZS - Tanzanian Shilling</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="edit_fee_type" name="fee_type">
                                <option value="normal">Normal</option>
                                <option value="liberty">Liberty</option>
                                <option value="this_trade">This Trade</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_default_brokerage_fee" class="form-label">Default Brokerage Fee (%)</label>
                            <input type="number" step="0.01" class="form-control" id="edit_default_brokerage_fee" name="default_brokerage_fee">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_client" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>
                        Save Changes
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

<!-- Portal Self-Service Link Modal -->
<div class="modal fade" id="portalLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link-45deg text-primary me-1"></i> Send Client Update Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Generate a secure link for the client to <strong>update their own phone, email and bank/payment details</strong>.
                    The link is shown <strong>only once</strong>, expires after the set hours, and can be used up to the
                    allowed number of times. Generating a new link automatically revokes any previous one.
                </div>

                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-person-circle fs-3 text-secondary me-2"></i>
                    <div>
                        <div class="fw-semibold" id="portalClientName">-</div>
                        <small class="text-muted">CDS: <span id="portalClientCds">-</span></small>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="portalExpiryHours" class="form-label">Link validity (hours)</label>
                        <input type="number" class="form-control" id="portalExpiryHours" value="72" min="1" max="720">
                        <div class="form-text">Default 72 hours (3 days).</div>
                    </div>
                    <div class="col-md-6">
                        <label for="portalMaxUses" class="form-label">Maximum uses</label>
                        <input type="number" class="form-control" id="portalMaxUses" value="20" min="1" max="100">
                        <div class="form-text">Default 20 uses.</div>
                    </div>
                </div>

                <div id="portalGenerateStatus" class="mb-2"></div>

                <div id="portalLinkResult" class="d-none">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Clients can now self-serve using the public URL. Provide this to them: <strong>https://clients.vfsl.co.tz/</strong>
                    </div>

                    <label class="form-label fw-semibold">Or generate a one-off secure link:</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="portalLinkText" readonly>
                        <button class="btn btn-outline-success" type="button" id="portalCopyBtn" onclick="portalCopyLink()">
                            <i class="bi bi-clipboard me-1"></i>Copy
                        </button>
                    </div>
                    <div class="form-text mb-2" id="portalLinkMeta"></div>
                    <div class="alert alert-warning py-2 mb-2">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        This link is only shown once. Store it in your message to the client now.
                    </div>
                    <button class="btn btn-outline-danger btn-sm" type="button" onclick="portalRevokeLinks(true)">
                        <i class="bi bi-slash-circle me-1"></i>Revoke active links for this client
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="portalGenerateBtn" onclick="portalGenerateLink()">
                    <i class="bi bi-magic me-1"></i>Generate Link
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Submissions Queue Modal -->
<div class="modal fade" id="submissionsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-inbox me-2"></i>Client Submissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-primary active" onclick="loadSubmissions('pending', this)">Pending</button>
                    <button class="btn btn-sm btn-outline-success" onclick="loadSubmissions('approved', this)">Approved</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="loadSubmissions('rejected', this)">Rejected</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadSubmissions('all', this)">All</button>
                </div>
                <div id="submissionsStatus"></div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>CDS</th>
                                <th>Client / Contact</th>
                                <th>Match%</th>
                                <th>Address</th>
                                <th>Payment Details</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="submissionsBody">
                            <tr><td colspan="8" class="text-center text-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Verification Queue Modal -->
<div class="modal fade" id="verificationQueueModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Verification Pending Queue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>CDS</th>
                            <th>Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="verificationQueueBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="delete_client" value="1">
    <input type="hidden" name="client_id" id="delete_client_id">
</form>

<script>
function showEditModal(clientData) {
    document.getElementById('edit_client_id').value = clientData.id;
    document.getElementById('edit_client_name').value = clientData.client_name || '';
    document.getElementById('edit_cds_account').value = clientData.cds_account || '';
    document.getElementById('edit_client_type').value = clientData.client_type || 'individual';
    document.getElementById('edit_status').value = clientData.status || 'active';
    document.getElementById('edit_national_id').value = clientData.national_id || '';
    document.getElementById('edit_date_of_birth').value = clientData.date_of_birth || '';
    document.getElementById('edit_client_code').value = clientData.client_code || '';
    document.getElementById('edit_phone').value = clientData.phone || '';
    document.getElementById('edit_email').value = clientData.email || '';
    document.getElementById('edit_address').value = clientData.address || '';
    document.getElementById('edit_bank_account_number').value = clientData.bank_account_number || '';
    document.getElementById('edit_bank_name').value = clientData.bank_name || '';
    document.getElementById('edit_bank_branch').value = clientData.bank_branch || '';
    document.getElementById('edit_currency').value = clientData.currency || 'TZS';
    document.getElementById('edit_fee_type').value = clientData.fee_type || 'normal';
    document.getElementById('edit_default_brokerage_fee').value = clientData.default_brokerage_fee || '';
    
    new bootstrap.Modal(document.getElementById('editClientModal')).show();
}

function confirmDelete(clientId, clientName) {
    if (confirm(`Are you sure you want to delete client "${clientName}"?\n\nNote: This will only soft delete the client (set to inactive).`)) {
        document.getElementById('delete_client_id').value = clientId;
        document.getElementById('deleteForm').submit();
    }
}

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

// ===== Client Self-Service Portal Link =====
const PORTAL_API_URL = '/api/portal/index.php';
let portalCsrfToken = null;

function portalEnsureCsrfToken() {
    if (portalCsrfToken) {
        return Promise.resolve(portalCsrfToken);
    }
    return fetch(PORTAL_API_URL + '?action=csrf_token')
        .then(r => r.json())
        .then(body => {
            if (!body.success) throw new Error(body.error || 'Could not obtain a security token.');
            portalCsrfToken = body.data.csrf_token;
            return portalCsrfToken;
        });
}

function portalShowStatus(message, type) {
    const el = document.getElementById('portalGenerateStatus');
    if (!message) { el.innerHTML = ''; return; }
    const icons = { success: 'check-circle-fill', danger: 'exclamation-triangle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
    el.innerHTML = `<div class="alert alert-${type} py-2 mb-0"><i class="bi bi-${icons[type] || 'info-circle-fill'} me-1"></i>${message}</div>`;
}

function portalSetBusy(busy) {
    const btn = document.getElementById('portalGenerateBtn');
    btn.disabled = busy;
    btn.querySelector('i').className = busy ? 'bi bi-hourglass-split me-1' : 'bi bi-magic me-1';
}

function showPortalLinkModal(clientName, cdsAccount) {
    document.getElementById('portalClientName').textContent = clientName || '-';
    document.getElementById('portalClientCds').textContent = cdsAccount || '-';
    document.getElementById('portalExpiryHours').value = 72;
    document.getElementById('portalMaxUses').value = 20;
    document.getElementById('portalLinkResult').classList.add('d-none');
    document.getElementById('portalLinkText').value = '';
    document.getElementById('portalLinkMeta').textContent = '';
    portalShowStatus('', null);
    portalSetBusy(false);
    new bootstrap.Modal(document.getElementById('portalLinkModal')).show();
}

function portalGenerateLink() {
    const cds = document.getElementById('portalClientCds').textContent.trim();
    const expiresHours = Math.min(720, Math.max(1, parseInt(document.getElementById('portalExpiryHours').value, 10) || 72));
    const maxUses = Math.min(100, Math.max(1, parseInt(document.getElementById('portalMaxUses').value, 10) || 20));
    const payload = { cds_account: cds, expires_hours: expiresHours, max_uses: maxUses };

    portalSetBusy(true);
    portalShowStatus('', null);

    portalEnsureCsrfToken()
        .then(token => {
            payload.csrf_token = token;
            return fetch(PORTAL_API_URL + '?action=mint_link', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        })
        .then(async resp => {
            const text = await resp.text();
            try {
                const body = JSON.parse(text);
                return { status: resp.status, body };
            } catch (e) {
                throw Object.assign(
                    new Error('Server returned an unexpected response (not JSON). Check the server logs or contact the administrator.'),
                    { status: resp.status, rawText: text.substring(0, 300) }
                );
            }
        })
        .then(({ status, body }) => {
            if (!body.success) throw Object.assign(new Error(body.error || 'Failed to generate the link.'), { status, details: body.details });
            const data = body.data;
            document.getElementById('portalLinkText').value = data.link;
            document.getElementById('portalLinkMeta').textContent =
                `Valid until ${data.expires_at} \u00b7 up to ${data.max_uses} uses \u00b7 ${data.client_name} (CDS ${data.cds_account}).`;
            document.getElementById('portalLinkResult').classList.remove('d-none');
            portalShowStatus('Link generated \u2014 copy it and send it to the client now.', 'success');
        })
        .catch(err => {
            const msgs = {
                401: 'Your session has expired. Please log in again.',
                403: 'Security token expired. Please log in again and retry.',
                404: 'Client not found. Reload the page and try again.',
                405: 'Server rejected the request method. Contact the administrator.',
                429: 'Too many requests. Wait a minute and try again.'
            };
            const msg = msgs[err.status] || err.message;
            let detail = '';
            if (err.rawText && err.rawText.includes('<')) {
                detail = ' The server returned an HTML page instead of JSON \u2014 the API endpoint may be misconfigured on the server.';
            }
            portalShowStatus(msg + detail, 'danger');
        })
        .finally(() => portalSetBusy(false));
}

function portalCopyLink() {
    const input = document.getElementById('portalLinkText');
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(() => {
            const btn = document.getElementById('portalCopyBtn');
            const old = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copied!';
            btn.classList.add('btn-success');
            btn.classList.remove('btn-outline-success');
            setTimeout(() => { btn.innerHTML = old; btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success'); }, 2000);
        });
    } else {
        input.select();
        input.setSelectionRange(0, 99999);
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
    }
}

function portalRevokeLinks(showConfirmation) {
    const cds = document.getElementById('portalClientCds').textContent.trim();
    if (!cds || cds === '-') return;
    if (showConfirmation && !confirm(`Revoke all active update links for CDS ${cds}?`)) return;

    portalEnsureCsrfToken()
        .then(token => fetch(PORTAL_API_URL + '?action=revoke_link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: token, cds_account: cds })
        }))
        .then(resp => resp.json().then(body => ({ status: resp.status, body })))
        .then(({ status, body }) => {
            if (!body.success) throw Object.assign(new Error(body.error || 'Failed to revoke links.'), { status });
            document.getElementById('portalLinkResult').classList.add('d-none');
            portalShowStatus('Revoked ' + body.data.revoked_tokens + ' active link(s) for this client.', 'success');
        })
        .catch(err => portalShowStatus(err.message, 'danger'));
}

// ===== Client Submissions Queue =====
function portalEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function portalSubmissionPaymentSummary(submission) {
    let methods = {};
    if (submission.payment_methods) {
        try {
            methods = typeof submission.payment_methods === 'string'
                ? JSON.parse(submission.payment_methods)
                : submission.payment_methods;
        } catch (e) {
            methods = {};
        }
    }

    if ((!methods || Object.keys(methods).length === 0) &&
        (submission.bank_name || submission.bank_account_number || submission.bank_branch)) {
        methods = {
            bank: {
                bank_name: submission.bank_name || '',
                account_number: submission.bank_account_number || '',
                branch: submission.bank_branch || '',
                currency: submission.currency || 'TZS'
            }
        };
    }

    const rows = [];
    if (methods.bank) {
        const bank = methods.bank;
        const detail = [bank.bank_name, bank.account_number || bank.bank_account_number, bank.branch || bank.bank_branch]
            .filter(Boolean).map(portalEscapeHtml).join(' · ');
        rows.push(`<div><span class="badge bg-primary me-1">Bank</span><small>${detail || '-'}</small></div>`);
    }
    if (methods.phone) {
        const phone = methods.phone;
        const detail = [phone.provider, phone.phone_number || phone.phone]
            .filter(Boolean).map(portalEscapeHtml).join(' · ');
        rows.push(`<div class="mt-1"><span class="badge bg-info text-dark me-1">Phone</span><small>${detail || '-'}</small></div>`);
    }
    if (methods.selcom) {
        const selcom = methods.selcom;
        const detail = [selcom.account_name || selcom.name, selcom.account_number || selcom.account]
            .filter(Boolean).map(portalEscapeHtml).join(' · ');
        rows.push(`<div class="mt-1"><span class="badge bg-secondary me-1">Selcom</span><small>${detail || '-'}</small></div>`);
    }

    return rows.length ? rows.join('') : '<span class="text-muted">-</span>';
}

function loadSubmissions(status, btn) {
    status = status || 'pending';
    if (btn) {
        document.querySelectorAll('#submissionsModal .btn-sm').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    document.getElementById('submissionsBody').innerHTML = '<tr><td colspan="8" class="text-center text-muted">Loading…</td></tr>';

    fetch(PORTAL_API_URL + '?action=list_submissions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: status, limit: 50 })
    })
    .then(r => r.json())
    .then(body => {
        if (!body.success) throw new Error(body.error);
        const subs = body.data.submissions || [];
        if (subs.length === 0) {
            document.getElementById('submissionsBody').innerHTML = '<tr><td colspan="8" class="text-center text-muted">No submissions.</td></tr>';
            return;
        }

        const statusBadges = { pending: 'warning', approved: 'success', rejected: 'danger' };
        document.getElementById('submissionsBody').innerHTML = subs.map(s => {
            const matchPct = Number(s.match_pct || 0);
            const contactBits = [];
            if (s.phone) contactBits.push(portalEscapeHtml(s.phone));
            if (s.email) contactBits.push(portalEscapeHtml(s.email));
            const reviewer = portalEscapeHtml(s.reviewer_name || '');
            const notes = portalEscapeHtml(s.review_notes || '');

            return `
                <tr>
                    <td>${portalEscapeHtml(s.cds_account || '-')}</td>
                    <td>
                        <div class="fw-semibold">${portalEscapeHtml(s.submitted_name || '-')}</div>
                        ${contactBits.length ? `<small class="text-muted">${contactBits.join(' · ')}</small>` : ''}
                    </td>
                    <td><span class="badge bg-${matchPct >= 60 ? 'success' : 'danger'}">${matchPct}%</span></td>
                    <td><small>${portalEscapeHtml(s.address || '-')}</small></td>
                    <td style="min-width: 250px">${portalSubmissionPaymentSummary(s)}</td>
                    <td><span class="badge bg-${statusBadges[s.status] || 'secondary'}">${portalEscapeHtml(s.status || '-')}</span></td>
                    <td>${portalEscapeHtml(s.created_at || '-')}</td>
                    <td>${s.status === 'pending' ?
                        `<button class="btn btn-sm btn-success me-1" onclick="reviewSubmission(${Number(s.id)}, 'approve')" title="Approve"><i class="bi bi-check-lg"></i></button><button class="btn btn-sm btn-danger" onclick="reviewSubmission(${Number(s.id)}, 'reject')" title="Reject"><i class="bi bi-x-lg"></i></button>` :
                        (s.status === 'approved' ? `<small class="text-muted">${reviewer}</small>` : `<small class="text-muted" title="${notes}">${reviewer}</small>`)
                    }</td>
                </tr>`;
        }).join('');
        new bootstrap.Modal(document.getElementById('submissionsModal')).show();
    })
    .catch(err => {
        document.getElementById('submissionsBody').innerHTML = `<tr><td colspan="8" class="text-danger">${portalEscapeHtml(err.message)}</td></tr>`;
        new bootstrap.Modal(document.getElementById('submissionsModal')).show();
    });
}

function reviewSubmission(id, action) {
    const isApprove = action === 'approve';
    let reason = '';
    if (!isApprove) {
        reason = prompt('Rejection reason:');
        if (reason === null || reason.trim() === '') return;
    }
    if (!isApprove && !confirm('Reject submission #' + id + '?')) return;
    if (isApprove && !confirm('Approve submission #' + id + '? This will update the client record.')) return;

    fetch(PORTAL_API_URL + '?action=' + (isApprove ? 'approve_submission' : 'reject_submission'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ submission_id: id, reason: reason })
    })
    .then(r => r.json())
    .then(body => {
        if (!body.success) throw new Error(body.error);
        alert('Done: ' + body.message);
        loadSubmissions(document.querySelector('#submissionsModal .btn-sm.active')?.textContent?.toLowerCase() || 'pending');
    })
    .catch(err => alert('Error: ' + err.message));
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
});
</script>

<?php include '../includes/footer.php'; ?>