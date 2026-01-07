<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

$db = getDBConnection();

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_item':
                $id = $_POST['id'];
                $code = $_POST['code'];
                $description = $_POST['description'];
                $activity = $_POST['activity'];
                $format = $_POST['format'];
                $reverse = $_POST['reverse'];
                $hpal = $_POST['hpal'];
                $hbral = $_POST['hbral'];
                $priority = $_POST['priority'];
                
                $stmt = $db->prepare("UPDATE cash_flow_formats SET code = ?, description = ?, activity = ?, format = ?, reverse = ?, hpal = ?, hbral = ?, priority = ? WHERE id = ?");
                $stmt->execute([$code, $description, $activity, $format, $reverse, $hpal, $hbral, $priority, $id]);
                
                $_SESSION['success_message'] = "Cash flow item updated successfully!";
                break;
                
            case 'toggle_active':
                $id = $_POST['id'];
                $stmt = $db->prepare("UPDATE cash_flow_formats SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['success_message'] = "Item status updated successfully!";
                break;
                
            case 'bulk_delete':
                if (isset($_POST['selected_items'])) {
                    $placeholders = str_repeat('?,', count($_POST['selected_items']) - 1) . '?';
                    $stmt = $db->prepare("DELETE FROM cash_flow_formats WHERE id IN ($placeholders)");
                    $stmt->execute($_POST['selected_items']);
                    $_SESSION['success_message'] = "Selected items deleted successfully!";
                }
                break;
        }
        
        header("Location: cash_flow_configuration.php");
        exit();
    }
}

// Get all cash flow items
$stmt = $db->query("SELECT * FROM cash_flow_formats ORDER BY priority");
$cash_flow_items = $stmt->fetchAll();

$page_title = 'Cash Flow Statement Configuration';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);">
                            <i class="bi bi-file-earmark-bar-graph text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Cash Flow Statement Configuration</h1>
                        <p class="page-subtitle">Manage cash flow statement line items and formatting rules</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Financial Reporting</span>
                    <span class="fw-semibold">NEOVAM</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list-check me-2"></i>
                        Cash Flow Line Items (<?php echo count($cash_flow_items); ?> items)
                    </h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addItemModal">
                            <i class="bi bi-plus-circle me-1"></i>Add Item
                        </button>
                        <a href="master_data.php" class="btn btn-light btn-sm">
                            <i class="bi bi-arrow-left me-1"></i>Back to Master Data
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Bulk Actions -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="checkAllBtn">
                                    <i class="bi bi-check-square me-1"></i>Check All
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="uncheckAllBtn">
                                    <i class="bi bi-square me-1"></i>Uncheck All
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="deleteSelectedBtn">
                                    <i class="bi bi-trash me-1"></i>Delete Selected
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="input-group" style="max-width: 300px; margin-left: auto;">
                                <input type="text" class="form-control form-control-sm" placeholder="Search items..." id="searchInput">
                                <button class="btn btn-outline-secondary btn-sm" type="button">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Flow Items Table -->
                    <form id="bulkActionForm" method="POST">
                        <input type="hidden" name="action" value="bulk_delete">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" class="form-check-input" id="selectAll">
                                        </th>
                                        <th>Code</th>
                                        <th>Description</th>
                                        <th>Activity</th>
                                        <th>Format</th>
                                        <th>Reverse</th>
                                        <th>HPAL</th>
                                        <th>HBraL</th>
                                        <th>Priority</th>
                                        <th>Count</th>
                                        <th>%</th>
                                        <th width="100">Status</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cash_flow_items as $item): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input item-checkbox" name="selected_items[]" value="<?php echo $item['id']; ?>">
                                            </td>
                                            <td>
                                                <span class="fw-bold text-primary"><?php echo htmlspecialchars($item['code']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $item['activity'] == 'OPERATING' ? 'info' : 
                                                         ($item['activity'] == 'INVESTING' ? 'success' : 
                                                         ($item['activity'] == 'FINANCING' ? 'warning' : 'secondary')); 
                                                ?>">
                                                    <?php echo htmlspecialchars($item['activity']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['format']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['reverse'] == 'YES' ? 'success' : 'secondary'; ?>">
                                                    <?php echo htmlspecialchars($item['reverse']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['hpal'] == 'YES' ? 'warning' : 'secondary'; ?>">
                                                    <?php echo htmlspecialchars($item['hpal']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['hbral'] == 'YES' ? 'danger' : 'secondary'; ?>">
                                                    <?php echo htmlspecialchars($item['hbral']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="fw-bold"><?php echo htmlspecialchars($item['priority']); ?></span>
                                            </td>
                                            <td><?php echo $item['item_count']; ?></td>
                                            <td><?php echo number_format($item['percentage'], 2); ?>%</td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" class="btn btn-sm <?php echo $item['is_active'] ? 'btn-success' : 'btn-secondary'; ?>">
                                                        <i class="bi bi-<?php echo $item['is_active'] ? 'check' : 'x'; ?>-circle"></i>
                                                        <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editItemModal"
                                                            data-id="<?php echo $item['id']; ?>"
                                                            data-code="<?php echo htmlspecialchars($item['code']); ?>"
                                                            data-description="<?php echo htmlspecialchars($item['description']); ?>"
                                                            data-activity="<?php echo htmlspecialchars($item['activity']); ?>"
                                                            data-format="<?php echo htmlspecialchars($item['format']); ?>"
                                                            data-reverse="<?php echo htmlspecialchars($item['reverse']); ?>"
                                                            data-hpal="<?php echo htmlspecialchars($item['hpal']); ?>"
                                                            data-hbral="<?php echo htmlspecialchars($item['hbral']); ?>"
                                                            data-priority="<?php echo htmlspecialchars($item['priority']); ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-<?php echo $item['is_active'] ? 'warning' : 'success'; ?>" 
                                                                onclick="return confirm('Are you sure you want to <?php echo $item['is_active'] ? 'deactivate' : 'activate'; ?> this item?')">
                                                            <i class="bi bi-<?php echo $item['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <?php if (empty($cash_flow_items)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted"></i>
                            <p class="mt-3 text-muted">No cash flow items configured yet.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                <i class="bi bi-plus-circle me-1"></i>Add First Item
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Cash Flow Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editItemForm">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="id" id="edit_item_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Code</label>
                            <input type="text" class="form-control" name="code" id="edit_code" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <input type="text" class="form-control" name="priority" id="edit_priority" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2" required></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Activity</label>
                            <select class="form-select" name="activity" id="edit_activity" required>
                                <option value="OPERATING">Operating</option>
                                <option value="INVESTING">Investing</option>
                                <option value="FINANCING">Financing</option>
                                <option value="TT">Total</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format" id="edit_format" required>
                                <option value="HD">Header (HD)</option>
                                <option value="VP">Value Part (VP)</option>
                                <option value="ST">Sub Total (ST)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reverse</label>
                            <select class="form-select" name="reverse" id="edit_reverse" required>
                                <option value="YES">Yes</option>
                                <option value="NO">No</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">HPAL</label>
                            <select class="form-select" name="hpal" id="edit_hpal" required>
                                <option value="YES">Yes</option>
                                <option value="NO">No</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">HBraL</label>
                            <select class="form-select" name="hbral" id="edit_hbral" required>
                                <option value="YES">Yes</option>
                                <option value="NO">No</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Cash Flow Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="add_cash_flow_item.php">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Code</label>
                            <input type="text" class="form-control" name="code" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <input type="text" class="form-control" name="priority" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2" required></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Activity</label>
                            <select class="form-select" name="activity" required>
                                <option value="OPERATING">Operating</option>
                                <option value="INVESTING">Investing</option>
                                <option value="FINANCING">Financing</option>
                                <option value="TT">Total</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format" required>
                                <option value="HD">Header (HD)</option>
                                <option value="VP">Value Part (VP)</option>
                                <option value="ST">Sub Total (ST)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reverse</label>
                            <select class="form-select" name="reverse" required>
                                <option value="YES">Yes</option>
                                <option value="NO">No</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">HPAL</label>
                            <select class="form-select" name="hpal" required>
                                <option value="YES">Yes</option>
                                <option value="NO">No</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">HBraL</label>
                            <select class="form-select" name="hbral" required>
                                <option value="YES">Yes</option>
                                <option value="NO">No</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit modal functionality
document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editItemModal');
    editModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('edit_item_id').value = button.getAttribute('data-id');
        document.getElementById('edit_code').value = button.getAttribute('data-code');
        document.getElementById('edit_description').value = button.getAttribute('data-description');
        document.getElementById('edit_activity').value = button.getAttribute('data-activity');
        document.getElementById('edit_format').value = button.getAttribute('data-format');
        document.getElementById('edit_reverse').value = button.getAttribute('data-reverse');
        document.getElementById('edit_hpal').value = button.getAttribute('data-hpal');
        document.getElementById('edit_hbral').value = button.getAttribute('data-hbral');
        document.getElementById('edit_priority').value = button.getAttribute('data-priority');
    });

    // Bulk actions
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.item-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });

    document.getElementById('checkAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            checkbox.checked = true;
        });
        document.getElementById('selectAll').checked = true;
    });

    document.getElementById('uncheckAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        document.getElementById('selectAll').checked = false;
    });

    document.getElementById('deleteSelectedBtn').addEventListener('click', function() {
        const selectedItems = document.querySelectorAll('.item-checkbox:checked');
        if (selectedItems.length === 0) {
            alert('Please select at least one item to delete.');
            return;
        }
        
        if (confirm(`Are you sure you want to delete ${selectedItems.length} selected item(s)?`)) {
            document.getElementById('bulkActionForm').submit();
        }
    });

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>