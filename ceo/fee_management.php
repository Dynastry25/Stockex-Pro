<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$db = getDBConnection();

// Initialize variables for the form
$edit_id = null;
$fee_type = '';
$fee_name = '';
$rate_percentage = 0.00;
$fixed_amount = 0.00;
$applies_to = 'ALL';
$calculation_base = 'CONSIDERATION';
$is_active = 1;
$description = '';

// Handle form submissions for adding/editing fees
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $fee_type = filter_input(INPUT_POST, 'fee_type', FILTER_SANITIZE_SPECIAL_CHARS);
    $fee_name = filter_input(INPUT_POST, 'fee_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $rate_percentage = filter_input(INPUT_POST, 'rate_percentage', FILTER_VALIDATE_FLOAT);
    $fixed_amount = filter_input(INPUT_POST, 'fixed_amount', FILTER_VALIDATE_FLOAT);
    $applies_to = filter_input(INPUT_POST, 'applies_to', FILTER_SANITIZE_SPECIAL_CHARS);
    $calculation_base = filter_input(INPUT_POST, 'calculation_base', FILTER_SANITIZE_SPECIAL_CHARS);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS);
    $form_action = $_POST['action'];

    try {
        if ($form_action === 'add') {
            $sql = "INSERT INTO fee_configurations (fee_type, fee_name, rate_percentage, fixed_amount, applies_to, calculation_base, is_active, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$fee_type, $fee_name, $rate_percentage, $fixed_amount, $applies_to, $calculation_base, $is_active, $description]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Fee configuration added successfully!'];
        } elseif ($form_action === 'edit' && isset($_POST['id'])) {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $sql = "UPDATE fee_configurations SET fee_type = ?, fee_name = ?, rate_percentage = ?, fixed_amount = ?, applies_to = ?, calculation_base = ?, is_active = ?, description = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$fee_type, $fee_name, $rate_percentage, $fixed_amount, $applies_to, $calculation_base, $is_active, $description, $id]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Fee configuration updated successfully!'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    }

    // Redirect to prevent form resubmission
    header('Location: fee_management.php');
    exit;
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    try {
        $sql = "DELETE FROM fee_configurations WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Fee configuration deleted successfully!'];
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Error deleting fee: ' . $e->getMessage()];
    }
    header('Location: fee_management.php');
    exit;
}

// Handle edit request: populate form with existing data
if (isset($_GET['edit'])) {
    $edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    try {
        $stmt = $db->prepare("SELECT * FROM fee_configurations WHERE id = ?");
        $stmt->execute([$edit_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            $fee_type = $data['fee_type'];
            $fee_name = $data['fee_name'];
            $rate_percentage = $data['rate_percentage'];
            $fixed_amount = $data['fixed_amount'];
            $applies_to = $data['applies_to'];
            $calculation_base = $data['calculation_base'];
            $is_active = $data['is_active'];
            $description = $data['description'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Error fetching fee data: ' . $e->getMessage()];
    }
}

// Fetch all fee configurations for display
try {
    $stmt = $db->query("SELECT * FROM fee_configurations ORDER BY created_at DESC");
    $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Error fetching fees: ' . $e->getMessage()];
    $fees = [];
}

$page_title = 'Fee Management';
include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4"><i class="bi bi-gear-fill"></i> Fee Management</h1>
        </div>
    </div>

    <!-- Display session messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['message']['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['message']['text']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Fee Management Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <?php echo $edit_id ? 'Edit Fee Configuration' : 'Add New Fee Configuration'; ?>
            </h5>
        </div>
        <div class="card-body">
            <form action="fee_management.php" method="POST">
                <input type="hidden" name="action" value="<?php echo $edit_id ? 'edit' : 'add'; ?>">
                <?php if ($edit_id): ?>
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_id); ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="fee_name" class="form-label">Fee Name</label>
                        <input type="text" class="form-control" id="fee_name" name="fee_name" value="<?php echo htmlspecialchars($fee_name); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="fee_type" class="form-label">Fee Type</label>
                        <select class="form-select" id="fee_type" name="fee_type" required>
                            <option value="BROKERAGE" <?php echo ($fee_type === 'BROKERAGE') ? 'selected' : ''; ?>>Brokerage</option>
                            <option value="VAT" <?php echo ($fee_type === 'VAT') ? 'selected' : ''; ?>>VAT</option>
                            <option value="CMSA" <?php echo ($fee_type === 'CMSA') ? 'selected' : ''; ?>>CMSA</option>
                            <option value="DSE" <?php echo ($fee_type === 'DSE') ? 'selected' : ''; ?>>DSE</option>
                            <option value="FIDELITY" <?php echo ($fee_type === 'FIDELITY') ? 'selected' : ''; ?>>Fidelity</option>
                            <option value="CDS" <?php echo ($fee_type === 'CDS') ? 'selected' : ''; ?>>CDS</option>
                            <option value="OTHER" <?php echo ($fee_type === 'OTHER') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="rate_percentage" class="form-label">Rate Percentage (%)</label>
                        <input type="number" step="0.0001" class="form-control" id="rate_percentage" name="rate_percentage" value="<?php echo htmlspecialchars($rate_percentage); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="fixed_amount" class="form-label">Fixed Amount (Tzs)</label>
                        <input type="number" step="0.01" class="form-control" id="fixed_amount" name="fixed_amount" value="<?php echo htmlspecialchars($fixed_amount); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="applies_to" class="form-label">Applies To</label>
                        <select class="form-select" id="applies_to" name="applies_to" required>
                            <option value="ALL" <?php echo ($applies_to === 'ALL') ? 'selected' : ''; ?>>All</option>
                            <option value="EQUITY" <?php echo ($applies_to === 'EQUITY') ? 'selected' : ''; ?>>Equity</option>
                            <option value="BOND" <?php echo ($applies_to === 'BOND') ? 'selected' : ''; ?>>Bond</option>
                            <option value="TREASURY_BILL" <?php echo ($applies_to === 'TREASURY_BILL') ? 'selected' : ''; ?>>Treasury Bill</option>
                            <option value="CORPORATE_BOND" <?php echo ($applies_to === 'CORPORATE_BOND') ? 'selected' : ''; ?>>Corporate Bond</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="calculation_base" class="form-label">Calculation Base</label>
                        <select class="form-select" id="calculation_base" name="calculation_base" required>
                            <option value="CONSIDERATION" <?php echo ($calculation_base === 'CONSIDERATION') ? 'selected' : ''; ?>>Consideration</option>
                            <option value="COMMISSION" <?php echo ($calculation_base === 'COMMISSION') ? 'selected' : ''; ?>>Commission</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?php echo $is_active ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Is Active?</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($description); ?></textarea>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-success me-2">
                        <i class="bi bi-save"></i> Save Fee
                    </button>
                    <?php if ($edit_id): ?>
                        <a href="fee_management.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Fee List Table -->
    <div class="card shadow-sm rounded-4">
        <div class="card-header bg-white border-0">
            <h5 class="card-title mb-0">Existing Fee Configurations</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Applies To</th>
                            <th>Rate (%)</th>
                            <th>Fixed (Tzs)</th>
                            <th>Base</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fees)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No fee configurations found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fee['fee_name']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $fee['applies_to'])); ?></td>
                                    <td><?php echo htmlspecialchars($fee['rate_percentage']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['fixed_amount']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['calculation_base']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $fee['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $fee['is_active'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="fee_management.php?edit=<?php echo $fee['id']; ?>" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-pencil"></i> Edit</a>
                                        <a href="fee_management.php?delete=<?php echo $fee['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this fee configuration?');"><i class="bi bi-trash"></i> Delete</a>
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

<?php include '../includes/footer.php'; ?>
