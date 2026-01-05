<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();

$success_message = '';
$error_message = '';

function get_table_data($db, $table_name) {
    try {
        $stmt = $db->query("SELECT * FROM {$table_name} ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_lookup_entry($db, $table_name, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM {$table_name} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $table = $_POST['table_name'] ?? '';

    if (!in_array($table, ['bond_issuers', 'bond_types', 'bonds_economic_sectors', 'coupon_determiners', 'payment_frequencies', 'investments_costing_basis', 'share_market_segments', 'share_market_trends'])) {
        $error_message = "Invalid table name specified.";
    } else {
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        try {
            switch ($action) {
                case 'add':
                    if (empty($code) || empty($description)) {
                        throw new Exception("Code and Description are required fields.");
                    }
                    
                    // Check for duplicate code
                    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE code = ?");
                    $stmt->execute([$code]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception("Code '{$code}' already exists in {$table}.");
                    }
                    
                    // The 'investments_costing_basis', 'share_market_segments', and 'share_market_trends' tables do not have a status column.
                    if (in_array($table, ['investments_costing_basis', 'share_market_segments', 'share_market_trends'])) {
                        $stmt = $db->prepare("INSERT INTO {$table} (code, description) VALUES (?, ?)");
                        $stmt->execute([$code, $description]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO {$table} (code, description, status) VALUES (?, ?, ?)");
                        $stmt->execute([$code, $description, $status]);
                    }

                    $success_message = "New entry added to {$table} successfully.";
                    break;
                
                case 'update':
                    $id = (int)($_POST['id'] ?? 0);
                    if (!$id || empty($code) || empty($description)) {
                        throw new Exception("ID, Code, and Description are required for update.");
                    }
                    
                    // The 'investments_costing_basis', 'share_market_segments', and 'share_market_trends' tables do not have a status column.
                    if (in_array($table, ['investments_costing_basis', 'share_market_segments', 'share_market_trends'])) {
                        $stmt = $db->prepare("UPDATE {$table} SET code = ?, description = ? WHERE id = ?");
                        $stmt->execute([$code, $description, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE {$table} SET code = ?, description = ?, status = ? WHERE id = ?");
                        $stmt->execute([$code, $description, $status, $id]);
                    }
                    
                    $success_message = "Entry in {$table} updated successfully.";
                    break;

                case 'delete':
                    $id = (int)($_POST['id'] ?? 0);
                    if (!$id) {
                        throw new Exception("ID is required for deletion.");
                    }

                    // Before deleting, check for dependencies
                    $dependencies_found = false;
                    if ($table === 'bonds_economic_sectors') {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM trades WHERE asset_class = 'bond' AND economic_sector = (SELECT code FROM {$table} WHERE id = ?)");
                        $stmt->execute([$id]);
                        if ($stmt->fetchColumn() > 0) {
                            $dependencies_found = true;
                        }
                    }

                    if ($dependencies_found) {
                        throw new Exception("Cannot delete this entry. It is referenced by existing trades.");
                    }

                    $stmt = $db->prepare("DELETE FROM {$table} WHERE id = ?");
                    $stmt->execute([$id]);
                    $success_message = "Entry deleted from {$table} successfully.";
                    break;
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Fetch all data for display
$tables_to_manage = [
    'bond_issuers' => ['name' => 'Bond Issuers', 'fields' => ['code', 'issuer_name']],
    'bond_types' => ['name' => 'Bond Types', 'fields' => ['code', 'description', 'status']],
    'bonds_economic_sectors' => ['name' => 'Economic Sectors', 'fields' => ['code', 'description', 'status']],
    'coupon_determiners' => ['name' => 'Coupon Determiners', 'fields' => ['code', 'description', 'status']],
    'payment_frequencies' => ['name' => 'Payment Frequencies', 'fields' => ['code', 'description', 'status']],
    'investments_costing_basis' => ['name' => 'Investments Costing Basis', 'fields' => ['code', 'description']],
    'share_market_segments' => ['name' => 'Share Market Segments', 'fields' => ['code', 'description']],
    'share_market_trends' => ['name' => 'Share Market Trends', 'fields' => ['code', 'description']]
];

$all_data = [];
foreach ($tables_to_manage as $table => $info) {
    $all_data[$table] = get_table_data($db, $table);
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
                            <i class="bi bi-gear-fill" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Lookup Table Management</h1>
                        <p class="page-subtitle">Manage system-wide dropdown values for bonds</p>
                    </div>
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

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
        <?php foreach ($tables_to_manage as $table_name => $info): ?>
            <div class="col">
                <div class="card dashboard-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><?php echo $info['name']; ?></h6>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal-<?php echo $table_name; ?>">
                            <i class="bi bi-plus-circle me-1"></i> Add
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead style="background: linear-gradient(135deg, var(--background-secondary) 0%, var(--background-muted) 100%);">
                                    <tr>
                                        <th class="border-0 fw-semibold text-dark py-2">Code</th>
                                        <th class="border-0 fw-semibold text-dark py-2">Description</th>
                                        <?php if (isset($info['fields']['status'])): ?>
                                            <th class="border-0 fw-semibold text-dark py-2">Status</th>
                                        <?php endif; ?>
                                        <th class="border-0 fw-semibold text-dark py-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_data[$table_name])): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No data found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_data[$table_name] as $entry): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($entry['code']); ?></td>
                                                <td><?php echo htmlspecialchars($entry['description'] ?? $entry['issuer_name']); ?></td>
                                                <?php if (isset($entry['status'])): ?>
                                                    <td>
                                                        <span class="badge rounded-pill <?php echo ($entry['status'] === 'active') ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo htmlspecialchars(ucfirst($entry['status'])); ?>
                                                        </span>
                                                    </td>
                                                <?php endif; ?>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-outline-primary btn-sm"
                                                                 data-bs-toggle="modal"
                                                                 data-bs-target="#editModal-<?php echo $table_name; ?>"
                                                                 data-id="<?php echo $entry['id']; ?>"
                                                                 data-code="<?php echo htmlspecialchars($entry['code']); ?>"
                                                                 data-description="<?php echo htmlspecialchars($entry['description'] ?? $entry['issuer_name']); ?>"
                                                                 data-status="<?php echo htmlspecialchars($entry['status'] ?? 'active'); ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="table_name" value="<?php echo $table_name; ?>">
                                                            <input type="hidden" name="id" value="<?php echo $entry['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
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
        <?php endforeach; ?>
    </div>
</div>

<!-- Modals for Add and Edit -->
<?php foreach ($tables_to_manage as $table_name => $info): ?>
    <!-- Add Modal -->
    <div class="modal fade" id="addModal-<?php echo $table_name; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="table_name" value="<?php echo $table_name; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New <?php echo $info['name']; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="add-code-<?php echo $table_name; ?>" class="form-label">Code</label>
                            <input type="text" class="form-control" id="add-code-<?php echo $table_name; ?>" name="code" required>
                        </div>
                        <div class="mb-3">
                            <label for="add-desc-<?php echo $table_name; ?>" class="form-label">Description</label>
                            <input type="text" class="form-control" id="add-desc-<?php echo $table_name; ?>" name="description" required>
                        </div>
                        <?php if (isset($info['fields']['status'])): ?>
                            <div class="mb-3">
                                <label for="add-status-<?php echo $table_name; ?>" class="form-label">Status</label>
                                <select class="form-select" id="add-status-<?php echo $table_name; ?>" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal-<?php echo $table_name; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="table_name" value="<?php echo $table_name; ?>">
                    <input type="hidden" id="edit-id-<?php echo $table_name; ?>" name="id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit <?php echo $info['name']; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit-code-<?php echo $table_name; ?>" class="form-label">Code</label>
                            <input type="text" class="form-control" id="edit-code-<?php echo $table_name; ?>" name="code" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit-desc-<?php echo $table_name; ?>" class="form-label">Description</label>
                            <input type="text" class="form-control" id="edit-desc-<?php echo $table_name; ?>" name="description" required>
                        </div>
                        <?php if (isset($info['fields']['status'])): ?>
                            <div class="mb-3">
                                <label for="edit-status-<?php echo $table_name; ?>" class="form-label">Status</label>
                                <select class="form-select" id="edit-status-<?php echo $table_name; ?>" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const editModals = document.querySelectorAll('[id^="editModal-"]');
        editModals.forEach(modal => {
            modal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const code = button.getAttribute('data-code');
                const description = button.getAttribute('data-description');
                const status = button.getAttribute('data-status');
                const tableName = button.getAttribute('data-bs-target').substring(12);

                const modalForm = this;
                modalForm.querySelector(`#edit-id-${tableName}`).value = id;
                modalForm.querySelector(`#edit-code-${tableName}`).value = code;
                modalForm.querySelector(`#edit-desc-${tableName}`).value = description;

                const statusSelect = modalForm.querySelector(`#edit-status-${tableName}`);
                if (statusSelect) {
                    statusSelect.value = status;
                }
            });
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
