<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow CEOs, admins, and traders to access this page
require_login();
$user = get_logged_in_user();
$allowed_roles = ['system_admin', 'ceo', 'trader'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to access client management.', 'danger');
    redirect('auth/login.php');
}

require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle export to Excel
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    exportClientsToExcel($db);
    exit;
}

// Handle filtered export to Excel
if (isset($_GET['export_filtered']) && $_GET['export_filtered'] == 'excel') {
    exportFilteredClientsToExcel($db);
    exit;
}

// Handle CDS merging (from client profile)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_cds'])) {
    $primary_client_id = (int)$_POST['primary_client_id'];
    $merge_client_id = (int)$_POST['merge_client_id'];
    
    if ($primary_client_id && $merge_client_id && $primary_client_id != $merge_client_id) {
        try {
            // Get client details
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
            $stmt->execute([$primary_client_id]);
            $primary_client = $stmt->fetch();
            
            $stmt->execute([$merge_client_id]);
            $merge_client = $stmt->fetch();
            
            if ($primary_client && $merge_client) {
                // Check if merge already exists
                $stmt = $db->prepare("SELECT id FROM merged_cds_accounts WHERE (primary_cds_account = ? AND merged_cds_account = ?) OR (primary_cds_account = ? AND merged_cds_account = ?)");
                $stmt->execute([$primary_client['cds_account'], $merge_client['cds_account'], $merge_client['cds_account'], $primary_client['cds_account']]);
                $existing_merge = $stmt->fetch();
                
                if (!$existing_merge) {
                    // Create merged_cds_accounts table if it doesn't exist
                    $create_table_sql = "CREATE TABLE IF NOT EXISTS merged_cds_accounts (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        primary_cds_account VARCHAR(50) NOT NULL,
                        merged_cds_account VARCHAR(50) NOT NULL,
                        merged_by INT NOT NULL,
                        merged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        status ENUM('active', 'inactive') DEFAULT 'active',
                        FOREIGN KEY (merged_by) REFERENCES users(id) ON DELETE CASCADE,
                        INDEX idx_primary (primary_cds_account),
                        INDEX idx_merged (merged_cds_account)
                    )";
                    $db->exec($create_table_sql);
                    
                    // Insert merge record
                    $stmt = $db->prepare("INSERT INTO merged_cds_accounts (primary_cds_account, merged_cds_account, merged_by) VALUES (?, ?, ?)");
                    $stmt->execute([$primary_client['cds_account'], $merge_client['cds_account'], $_SESSION['user_id']]);
                    
                    $success_message = "CDS accounts merged successfully!";
                } else {
                    $error_message = "These CDS accounts are already merged.";
                }
            } else {
                $error_message = "One or both clients not found.";
            }
        } catch (Exception $e) {
            $error_message = "Error merging CDS accounts: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select two different clients to merge.";
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
                    client_name = ?, 
                    cds_account = ?, 
                    national_id = ?, 
                    date_of_birth = ?, 
                    phone = ?, 
                    email = ?, 
                    client_type = ?, 
                    address = ?, 
                    bank_account_number = ?, 
                    bank_name = ?, 
                    bank_branch = ?, 
                    default_brokerage_fee = ?, 
                    fee_type = ?, 
                    client_code = ?, 
                    status = ?, 
                    updated_at = NOW() 
                    WHERE id = ?");
                
                $stmt->execute([
                    $client_name, 
                    $cds_account, 
                    $national_id ?: null, 
                    $date_of_birth ?: null, 
                    $phone ?: null, 
                    $email ?: null, 
                    $client_type, 
                    $address ?: null,
                    $bank_account_number ?: null, 
                    $bank_name ?: null, 
                    $bank_branch ?: null,
                    $default_brokerage_fee, 
                    $fee_type, 
                    $client_code ?: null, 
                    $status, 
                    $client_id
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

// Get all clients with trade counts
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

// Function to export all clients to Excel
function exportClientsToExcel($db) {
    $sql = "
        SELECT
            c.*,
            COUNT(t.id) as trade_count,
            SUM(CASE WHEN t.asset_class = 'bond' THEN 1 ELSE 0 END) as bond_count,
            SUM(CASE WHEN t.asset_class = 'equity' THEN 1 ELSE 0 END) as equity_count,
            SUM(CASE WHEN t.asset_class = 'Exchange Traded Funds' THEN 1 ELSE 0 END) as etf_count
        FROM clients c
        LEFT JOIN trades t ON c.cds_account = t.client_cds_account AND t.status = 'active'
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY c.client_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="clients_export_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Start output
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Client Name</th>";
    echo "<th>CDS Account</th>";
    echo "<th>Client Type</th>";
    echo "<th>National ID</th>";
    echo "<th>Date of Birth</th>";
    echo "<th>Phone</th>";
    echo "<th>Email</th>";
    echo "<th>Address</th>";
    echo "<th>Bank Account</th>";
    echo "<th>Bank Name</th>";
    echo "<th>Bank Branch</th>";
    echo "<th>Client Code</th>";
    echo "<th>Status</th>";
    echo "<th>Total Trades</th>";
    echo "<th>Bond Trades</th>";
    echo "<th>Equity Trades</th>";
    echo "<th>ETF Trades</th>";
    echo "<th>Created At</th>";
    echo "</tr>";
    
    foreach ($clients as $client) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($client['client_name']) . "</td>";
        echo "<td>" . htmlspecialchars($client['cds_account']) . "</td>";
        echo "<td>" . ($client['client_type'] ? ucfirst($client['client_type']) : 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($client['national_id'] ?? '') . "</td>";
        echo "<td>" . (!empty($client['date_of_birth']) ? date('d/m/Y', strtotime($client['date_of_birth'])) : '') . "</td>";
        echo "<td>" . htmlspecialchars($client['phone'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['email'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['address'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['bank_account_number'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['bank_name'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['bank_branch'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['client_code'] ?? '') . "</td>";
        echo "<td>" . ($client['status'] ? ucfirst($client['status']) : 'N/A') . "</td>";
        echo "<td>" . $client['trade_count'] . "</td>";
        echo "<td>" . $client['bond_count'] . "</td>";
        echo "<td>" . $client['equity_count'] . "</td>";
        echo "<td>" . $client['etf_count'] . "</td>";
        echo "<td>" . date('d/m/Y H:i', strtotime($client['created_at'])) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    exit;
}

// Function to export filtered clients to Excel
function exportFilteredClientsToExcel($db) {
    global $search_query, $status_filter;
    
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
        ORDER BY c.client_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    $filename = 'filtered_clients_' . date('Ymd_His') . '.xls';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Start output
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th colspan='18' style='text-align:center;background-color:#f2f2f2;font-size:16px;'>FILTERED CLIENTS EXPORT</th>";
    echo "</tr>";
    echo "<tr>";
    echo "<td colspan='18'>";
    echo "<strong>Filters Applied:</strong><br>";
    echo "Status: " . ($status_filter ? ucfirst($status_filter) : 'All') . "<br>";
    if (!empty($search_query)) {
        echo "Search: " . htmlspecialchars($search_query) . "<br>";
    }
    echo "Total Clients: " . count($clients) . "<br>";
    echo "Generated: " . date('d/m/Y H:i:s');
    echo "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<th>Client Name</th>";
    echo "<th>CDS Account</th>";
    echo "<th>Client Type</th>";
    echo "<th>National ID</th>";
    echo "<th>Date of Birth</th>";
    echo "<th>Phone</th>";
    echo "<th>Email</th>";
    echo "<th>Address</th>";
    echo "<th>Bank Account</th>";
    echo "<th>Bank Name</th>";
    echo "<th>Bank Branch</th>";
    echo "<th>Client Code</th>";
    echo "<th>Status</th>";
    echo "<th>Total Trades</th>";
    echo "<th>Bond Trades</th>";
    echo "<th>Equity Trades</th>";
    echo "<th>ETF Trades</th>";
    echo "<th>Created At</th>";
    echo "</tr>";
    
    foreach ($clients as $client) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($client['client_name']) . "</td>";
        echo "<td>" . htmlspecialchars($client['cds_account']) . "</td>";
        echo "<td>" . ($client['client_type'] ? ucfirst($client['client_type']) : 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($client['national_id'] ?? '') . "</td>";
        echo "<td>" . (!empty($client['date_of_birth']) ? date('d/m/Y', strtotime($client['date_of_birth'])) : '') . "</td>";
        echo "<td>" . htmlspecialchars($client['phone'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['email'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['address'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['bank_account_number'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['bank_name'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['bank_branch'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($client['client_code'] ?? '') . "</td>";
        echo "<td>" . ($client['status'] ? ucfirst($client['status']) : 'N/A') . "</td>";
        echo "<td>" . $client['trade_count'] . "</td>";
        echo "<td>" . $client['bond_count'] . "</td>";
        echo "<td>" . $client['equity_count'] . "</td>";
        echo "<td>" . $client['etf_count'] . "</td>";
        echo "<td>" . date('d/m/Y H:i', strtotime($client['created_at'])) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    exit;
}

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
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportModal">
                        <i class="bi bi-download me-1"></i>
                        Export
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
                                    <small class="text-muted"><?php echo $client['client_type'] ? ucfirst($client['client_type']) : 'N/A'; ?> Client</small>
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
                                            <span class="badge bg-warning text-dark"><?php echo $client['equity_count']; ?> equities</span>
                                        <?php endif; ?>
                                        <?php if ($client['etf_count'] > 0): ?>
                                            <span class="badge" style="background-color: #6f42c1; color: white;"><?php echo $client['etf_count']; ?> ETFs</span>
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
                                        <button class="btn btn-outline-warning btn-sm" title="Merge Accounts" onclick="showMergeModal(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars(addslashes($client['client_name'])); ?>', '<?php echo htmlspecialchars($client['cds_account']); ?>')">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                        <button class="btn btn-outline-info btn-sm" title="Send Update Link - populate a secure link for the client to update their own details" onclick="showPortalLinkModal('<?php echo htmlspecialchars(addslashes($client['client_name'])); ?>', '<?php echo htmlspecialchars($client['cds_account']); ?>')">
                                            <i class="bi bi-send"></i>
                                        </button>
                                        <?php if (in_array($user['role'], ['system_admin', 'ceo'])): ?>
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
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="client_name" class="form-label">Client Name *</label>
                            <input type="text" class="form-control" id="client_name" name="client_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="cds_account" class="form-label">CDS Account *</label>
                            <input type="text" class="form-control" id="cds_account" name="cds_account" required>
                        </div>
                        <div class="col-md-6">
                            <label for="client_type" class="form-label">Client Type *</label>
                            <select class="form-select" id="client_type" name="client_type" required>
                                <option value="individual">Individual</option>
                                <option value="institution">Institution</option>
                                <option value="corporate">Corporate</option>
                                <option value="joint">Joint Account</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="national_id" class="form-label">National ID/Passport</label>
                            <input type="text" class="form-control" id="national_id" name="national_id">
                        </div>
                        <div class="col-md-6">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth">
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                        <div class="col-md-6">
                            <label for="client_code" class="form-label">Client Code</label>
                            <input type="text" class="form-control" id="client_code" name="client_code">
                        </div>
                        <div class="col-md-12">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="bank_account_number" class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="bank_account_number" name="bank_account_number">
                        </div>
                        <div class="col-md-6">
                            <label for="bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="bank_name" name="bank_name">
                        </div>
                        <div class="col-md-6">
                            <label for="bank_branch" class="form-label">Bank Branch</label>
                            <input type="text" class="form-control" id="bank_branch" name="bank_branch">
                        </div>
                        <div class="col-md-6">
                            <label for="currency" class="form-label">Currency</label>
                            <select class="form-select" id="currency" name="currency">
                                <option value="TZS">TZS - Tanzanian Shilling</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="fee_type" name="fee_type">
                                <option value="normal">Normal</option>
                                <option value="preferential">Preferential</option>
                                <option value="waived">Waived</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="default_brokerage_fee" class="form-label">Default Brokerage Fee (%)</label>
                            <input type="number" class="form-control" id="default_brokerage_fee" name="default_brokerage_fee" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_client" class="btn btn-primary">Add Client</button>
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
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_client_name" class="form-label">Client Name *</label>
                            <input type="text" class="form-control" id="edit_client_name" name="client_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_cds_account" class="form-label">CDS Account *</label>
                            <input type="text" class="form-control" id="edit_cds_account" name="cds_account" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_client_type" class="form-label">Client Type *</label>
                            <select class="form-select" id="edit_client_type" name="client_type" required>
                                <option value="individual">Individual</option>
                                <option value="institution">Institution</option>
                                <option value="corporate">Corporate</option>
                                <option value="joint">Joint Account</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_national_id" class="form-label">National ID/Passport</label>
                            <input type="text" class="form-control" id="edit_national_id" name="national_id">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="edit_date_of_birth" name="date_of_birth">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="edit_email" name="email">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_client_code" class="form-label">Client Code</label>
                            <input type="text" class="form-control" id="edit_client_code" name="client_code">
                        </div>
                        <div class="col-md-12">
                            <label for="edit_address" class="form-label">Address</label>
                            <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_bank_account_number" class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="edit_bank_account_number" name="bank_account_number">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="edit_bank_name" name="bank_name">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_bank_branch" class="form-label">Bank Branch</label>
                            <input type="text" class="form-control" id="edit_bank_branch" name="bank_branch">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_currency" class="form-label">Currency</label>
                            <select class="form-select" id="edit_currency" name="currency">
                                <option value="TZS">TZS - Tanzanian Shilling</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="edit_fee_type" name="fee_type">
                                <option value="normal">Normal</option>
                                <option value="preferential">Preferential</option>
                                <option value="waived">Waived</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_default_brokerage_fee" class="form-label">Default Brokerage Fee (%)</label>
                            <input type="number" class="form-control" id="edit_default_brokerage_fee" name="default_brokerage_fee" step="0.01" min="0">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label">Status *</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_client" class="btn btn-primary">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Merge CDS Modal -->
<div class="modal fade" id="mergeCdsModal" tabindex="-1" aria-labelledby="mergeCdsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" id="primary_client_id" name="primary_client_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="mergeCdsModalLabel">Merge CDS Accounts</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This will merge two CDS accounts. All trades from the merged account will be associated with the primary account.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Primary Account (Keep this account)</label>
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <div class="fw-semibold" id="primary_client_name"></div>
                                <small class="text-muted" id="primary_cds_account"></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="merge_client_id" class="form-label">Select Account to Merge</label>
                        <select class="form-select" id="merge_client_id" name="merge_client_id" required>
                            <option value="">Select client to merge...</option>
                            <?php foreach ($all_clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>">
                                    <?php echo htmlspecialchars($client['client_name']); ?> (<?php echo htmlspecialchars($client['cds_account']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This account will be merged into the primary account.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="merge_cds" class="btn btn-warning">Merge Accounts</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exportModalLabel">Export Clients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Export client data to Excel format for reporting and analysis.
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Export Options</label>
                    <div class="d-flex flex-column gap-2">
                        <a href="?export=excel" class="btn btn-outline-primary text-start">
                            <i class="bi bi-download me-2"></i>
                            Export All Active Clients
                        </a>
                        <a href="?export_filtered=excel<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo $status_filter !== 'active' ? '&status=' . urlencode($status_filter) : ''; ?>" 
                           class="btn btn-outline-success text-start">
                            <i class="bi bi-filter me-2"></i>
                            Export Filtered Results
                            <small class="d-block text-muted">(<?php echo $total_records; ?> clients)</small>
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" id="delete_client_id" name="client_id">
                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="deleteModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Deletion
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete client: <strong id="delete_client_name"></strong>?</p>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-octagon me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. The client will be marked as inactive and hidden from the system.
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirm_delete" required>
                        <label class="form-check-label" for="confirm_delete">
                            I understand this action cannot be undone
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_client" class="btn btn-danger">Delete Client</button>
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
                <h5 class="modal-title"><i class="bi bi-send text-info me-1"></i> Send Client Update Link</h5>
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
                    <label class="form-label fw-semibold">Send this link to the client:</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="portalLinkText" readonly>
                        <button class="btn btn-outline-success" type="button" id="portalCopyBtn" onclick="portalCopyLink()">
                            <i class="bi bi-clipboard me-1"></i>Copy
                        </button>
                    </div>
                    <div class="form-text mb-2" id="portalLinkMeta"></div>
                    <div class="alert alert-warning py-2 mb-2">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        This link is only shown once. Store it in your message to the client now. Anyone with the link
                        can update this client's details until it expires or is revoked.
                    </div>
                    <button class="btn btn-outline-danger btn-sm" type="button" onclick="portalRevokeLinks(true)">
                        <i class="bi bi-slash-circle me-1"></i>Revoke active links for this client
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info text-white" id="portalGenerateBtn" onclick="portalGenerateLink()">
                    <i class="bi bi-magic me-1"></i>Generate Link
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.badge.bg-purple, .badge[style*="background-color: #6f42c1"] {
    background-color: #6f42c1 !important;
    color: white !important;
}

.badge.bg-purple:hover, .badge[style*="background-color: #6f42c1"]:hover {
    background-color: #5a32a3 !important;
}
</style>

<script>
function showEditModal(client) {
    // Populate the edit modal with client data
    document.getElementById('edit_client_id').value = client.id;
    document.getElementById('edit_client_name').value = client.client_name;
    document.getElementById('edit_cds_account').value = client.cds_account;
    document.getElementById('edit_client_type').value = client.client_type;
    document.getElementById('edit_national_id').value = client.national_id || '';
    document.getElementById('edit_date_of_birth').value = client.date_of_birth ? client.date_of_birth.split(' ')[0] : '';
    document.getElementById('edit_phone').value = client.phone || '';
    document.getElementById('edit_email').value = client.email || '';
    document.getElementById('edit_address').value = client.address || '';
    document.getElementById('edit_bank_account_number').value = client.bank_account_number || '';
    document.getElementById('edit_bank_name').value = client.bank_name || '';
    document.getElementById('edit_bank_branch').value = client.bank_branch || '';
    document.getElementById('edit_currency').value = client.currency || 'TZS';
    document.getElementById('edit_client_code').value = client.client_code || '';
    document.getElementById('edit_fee_type').value = client.fee_type || 'normal';
    document.getElementById('edit_default_brokerage_fee').value = client.default_brokerage_fee || '';
    document.getElementById('edit_status').value = client.status || 'active';
    
    // Show the modal
    var editModal = new bootstrap.Modal(document.getElementById('editClientModal'));
    editModal.show();
}

function showMergeModal(clientId, clientName, cdsAccount) {
    // Set primary client info
    document.getElementById('primary_client_id').value = clientId;
    document.getElementById('primary_client_name').textContent = clientName;
    document.getElementById('primary_cds_account').textContent = cdsAccount;
    
    // Reset and refresh merge client dropdown (remove current client from options)
    var mergeSelect = document.getElementById('merge_client_id');
    mergeSelect.innerHTML = '<option value="">Select client to merge...</option>';
    
    // Add all clients except the current one
    <?php foreach ($all_clients as $client): ?>
        if (<?php echo $client['id']; ?> != clientId) {
            var option = document.createElement('option');
            option.value = <?php echo $client['id']; ?>;
            option.textContent = '<?php echo addslashes($client["client_name"]); ?> (<?php echo addslashes($client["cds_account"]); ?>)';
            mergeSelect.appendChild(option);
        }
    <?php endforeach; ?>
    
    // Show the modal
    var mergeModal = new bootstrap.Modal(document.getElementById('mergeCdsModal'));
    mergeModal.show();
}

function confirmDelete(clientId, clientName) {
    document.getElementById('delete_client_id').value = clientId;
    document.getElementById('delete_client_name').textContent = clientName;
    document.getElementById('confirm_delete').checked = false;
    
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

// Search functionality with debounce
let searchTimeout;
document.querySelector('input[name="search"]').addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        // Submit the form after 500ms of inactivity
        e.target.form.submit();
    }, 500);
});

// Auto-format phone number
document.getElementById('phone')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length <= 3) {
        e.target.value = value;
    } else if (value.length <= 6) {
        e.target.value = value.slice(0, 3) + '-' + value.slice(3);
    } else {
        e.target.value = value.slice(0, 3) + '-' + value.slice(3, 6) + '-' + value.slice(6, 10);
    }
});

// Auto-format national ID
document.getElementById('national_id')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length <= 2) {
        e.target.value = value;
    } else if (value.length <= 7) {
        e.target.value = value.slice(0, 2) + '-' + value.slice(2);
    } else {
        e.target.value = value.slice(0, 2) + '-' + value.slice(2, 7) + '-' + value.slice(7, 9);
    }
});

// Show success/error messages for modals
<?php if ($success_message || $error_message): ?>
    // Close any open modals
    var modals = document.querySelectorAll('.modal.show');
    modals.forEach(function(modal) {
        var modalInstance = bootstrap.Modal.getInstance(modal);
        if (modalInstance) {
            modalInstance.hide();
        }
    });
<?php endif; ?>

// ===== Client Self-Service Portal Link =====
const PORTAL_API_URL = '/api/portal/index';
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
        .then(resp => resp.json().then(body => ({ status: resp.status, body })))
        .then(({ status, body }) => {
            if (!body.success) throw Object.assign(new Error(body.error || 'Failed to generate the link.'), { status, details: body.details });
            const data = body.data;
            document.getElementById('portalLinkText').value = data.link;
            document.getElementById('portalLinkMeta').textContent =
                `Valid until ${data.expires_at} · up to ${data.max_uses} uses · ${data.client_name} (CDS ${data.cds_account}).`;
            document.getElementById('portalLinkResult').classList.remove('d-none');
            portalShowStatus('Link generated &mdash; copy it and send it to the client now.', 'success');
        })
        .catch(err => {
            const msgs = {
                401: 'Your session has expired. Please log in again.',
                403: 'Security token expired. Please log in again and retry.',
                404: 'Client not found. Reload the page and try again.',
                429: 'Too many requests. Wait a minute and try again.'
            };
            portalShowStatus(msgs[err.status] || err.message, 'danger');
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
</script>

<?php include '../includes/footer.php'; ?>