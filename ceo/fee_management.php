<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow both CEOs and admins to access this page
require_login();
$user = get_logged_in_user();
$allowed_roles = ['system_admin', 'ceo'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to access fee management.', 'danger');
    redirect('auth/login.php');
}

$db = getDBConnection();

$message = '';
$message_type = '';

// Handle form submissions for adding/editing fees
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? $_POST['id'] : null;
    $fee_name = $_POST['fee_name'];
    $fee_type = $_POST['fee_type'];
    $applies_to = $_POST['applies_to'];
    $rate_percentage = $_POST['rate_percentage'];
    $fixed_amount = $_POST['fixed_amount'];
    $calculation_base = $_POST['calculation_base'];

    if ($id) {
        // Update existing fee
        $stmt = $db->prepare("UPDATE fee_configurations SET fee_name = ?, fee_type = ?, applies_to = ?, rate_percentage = ?, fixed_amount = ?, calculation_base = ? WHERE id = ?");
        $stmt->execute([$fee_name, $fee_type, $applies_to, $rate_percentage, $fixed_amount, $calculation_base, $id]);
        $message = 'Fee configuration updated successfully!';
        $message_type = 'success';
    } else {
        // Add new fee
        $stmt = $db->prepare("INSERT INTO fee_configurations (fee_name, fee_type, applies_to, rate_percentage, fixed_amount, calculation_base) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fee_name, $fee_type, $applies_to, $rate_percentage, $fixed_amount, $calculation_base]);
        $message = 'New fee configuration added successfully!';
        $message_type = 'success';
    }
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $db->prepare("DELETE FROM fee_configurations WHERE id = ?");
    $stmt->execute([$id]);
    $message = 'Fee configuration deleted successfully!';
    $message_type = 'success';
    // Redirect to the same page to remove the deleted fee from the URL
    header('Location: fee_management?message=' . urlencode($message) . '&type=' . urlencode($message_type));
    exit;
}

// Get fee data for editing if an ID is provided
$fee_data = null;
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $db->prepare("SELECT * FROM fee_configurations WHERE id = ?");
    $stmt->execute([$id]);
    $fee_data = $stmt->fetch();
    if (!$fee_data) {
        $message = 'Fee not found.';
        $message_type = 'danger';
    }
}

// Get all fees for the table
$stmt = $db->query("SELECT * FROM fee_configurations ORDER BY fee_type, applies_to");
$fee_configs = $stmt->fetchAll();

// Display messages from redirects
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}

$page_title = 'Fee Management';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Fee Management</h1>
        <a href="DASHBOARD.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
        </a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card dashboard-card h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h5 class="card-title mb-0"><?php echo $fee_data ? 'Edit Fee' : 'Add New Fee'; ?></h5>
                </div>
                <div class="card-body">
                    <form action="fee_management.php" method="POST">
                        <?php if ($fee_data): ?>
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($fee_data['id']); ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="fee_name" class="form-label">Fee Name</label>
                            <input type="text" class="form-control" id="fee_name" name="fee_name" value="<?php echo $fee_data ? htmlspecialchars($fee_data['fee_name']) : ''; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="fee_type" class="form-label">Fee Type</label>
                            <select class="form-select" id="fee_type" name="fee_type" required>
                                <option value="fixed" <?php echo ($fee_data && $fee_data['fee_type'] == 'fixed') ? 'selected' : ''; ?>>Fixed</option>
                                <option value="percentage" <?php echo ($fee_data && $fee_data['fee_type'] == 'percentage') ? 'selected' : ''; ?>>Percentage</option>
                                <option value="combined" <?php echo ($fee_data && $fee_data['fee_type'] == 'combined') ? 'selected' : ''; ?>>Combined</option>
                            </select>
                        </div>
                        <div class="mb-3"> 
                            <label for="applies_to" class="form-label">Applies To</label>
                            <select class="form-select" id="applies_to" name="applies_to" required>
                                <option value="EQUITY" <?php echo ($fee_data && $fee_data['applies_to'] == 'EQUITY') ? 'selected' : ''; ?>>EQUITY</option>
                                <option value="BOND" <?php echo ($fee_data && $fee_data['applies_to'] == 'BOND') ? 'selected' : ''; ?>>BOND</option>
                                 <option value="CORPORATE_BOND" <?php echo ($fee_data && $fee_data['applies_to'] == 'CORPORATE_BOND') ? 'selected' : ''; ?>>CORPORATE_BOND</option>

                                 <option value="TREASURY_BILL" <?php echo ($fee_data && $fee_data['applies_to'] == 'TREASURY_BILL') ? 'selected' : ''; ?>>TREASURY_BILL</option>
                                                            <option value="ALL" <?php echo ($fee_data && $fee_data['applies_to'] == 'ALL') ? 'selected' : ''; ?>>ALL</option>

                                </select>
                        </div>
                        <div class="mb-3">
                            <label for="rate_percentage" class="form-label">Rate (%)</label>
                            <input type="number" step="0.01" class="form-control" id="rate_percentage" name="rate_percentage" value="<?php echo $fee_data ? htmlspecialchars($fee_data['rate_percentage']) : '0'; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="fixed_amount" class="form-label">Fixed Amount (Tsh)</label>
                            <input type="number" step="0.01" class="form-control" id="fixed_amount" name="fixed_amount" value="<?php echo $fee_data ? htmlspecialchars($fee_data['fixed_amount']) : '0'; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="calculation_base" class="form-label">Calculation Base</label>
                            <select class="form-select" id="calculation_base" name="calculation_base" required>
                                <option value="PRICE" <?php echo ($fee_data && $fee_data['calculation_base'] == 'PRICE') ? 'selected' : ''; ?>>Price</option>
                                <option value="CONSIDERATION" <?php echo ($fee_data && $fee_data['calculation_base'] == 'CONSIDERATION') ? 'selected' : ''; ?>>Consideration</option>
                                <option value="COMMISSION" <?php echo ($fee_data && $fee_data['calculation_base'] == 'COMMISSION') ? 'selected' : ''; ?>>Commission</option>
                            </select>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-save me-2"></i> <?php echo $fee_data ? 'Update Fee' : 'Add Fee'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card dashboard-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h5 class="card-title mb-0">Current Fee Configurations</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($fee_configs)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-cash-stack text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3 mb-0">No fee configurations to display</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="border-0">Fee Name</th>
                                        <th class="border-0">Type</th>
                                        <th class="border-0">Applies To</th>
                                        <th class="border-0">Rate (%)</th>
                                        <th class="border-0">Fixed Amount</th>
                                        <th class="border-0">Calculation Base</th>
                                        <th class="border-0">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fee_configs as $fee): ?>
                                        <tr>
                                            <td class="border-0 fw-medium"><?php echo htmlspecialchars($fee['fee_name']); ?></td>
                                            <td class="border-0">
                                                <span class="badge bg-secondary px-3 py-2">
                                                    <?php echo ucwords(str_replace('_', ' ', $fee['fee_type'])); ?>
                                                </span>
                                            </td>
                                            <td class="border-0"><?php echo ucwords(str_replace('_', ' ', $fee['applies_to'])); ?></td>
                                            <td class="border-0 text-muted"><?php echo number_format($fee['rate_percentage'], 2); ?>%</td>
                                            <td class="border-0 text-muted">Tsh <?php echo number_format($fee['fixed_amount'], 2); ?></td>
                                            <td class="border-0"><?php echo ucwords(str_replace('_', ' ', $fee['calculation_base'])); ?></td>
                                            <td class="border-0">
                                                <a href="fee_management.php?id=<?php echo $fee['id']; ?>" class="btn btn-sm btn-outline-primary me-2">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>
                                                <a href="fee_management.php?action=delete&id=<?php echo $fee['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this fee?');">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
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

<?php include '../includes/footer.php'; ?>