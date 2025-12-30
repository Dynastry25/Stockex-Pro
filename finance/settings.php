<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();

// Handle form submissions
if ($_POST) {
    if (isset($_POST['add_balance_sheet_item'])) {
        $category = $_POST['category'];
        $class = $_POST['class'];
        $item_name = $_POST['item_name'];
        $description = $_POST['description'];
        $account_code = $_POST['account_code'];
        
        $stmt = $db->prepare("INSERT INTO balance_sheet_items (category, class, item_name, description, account_code, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$category, $class, $item_name, $description, $account_code]);
        
        $_SESSION['success_message'] = "Balance sheet item added successfully!";
    }
    
    if (isset($_POST['add_cashflow_component'])) {
        $category = $_POST['cashflow_category'];
        $component_name = $_POST['component_name'];
        $description = $_POST['cashflow_description'];
        $calculation_basis = $_POST['calculation_basis'];
        
        $stmt = $db->prepare("INSERT INTO cashflow_components (category, component_name, description, calculation_basis, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$category, $component_name, $description, $calculation_basis]);
        
        $_SESSION['success_message'] = "Cash flow component added successfully!";
    }
    
    if (isset($_POST['add_income_statement_item'])) {
        $category = $_POST['income_category'];
        $item_name = $_POST['income_item_name'];
        $description = $_POST['income_description'];
        $item_type = $_POST['item_type']; // revenue or expense
        
        $stmt = $db->prepare("INSERT INTO income_statement_items (category, item_name, description, item_type, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$category, $item_name, $description, $item_type]);
        
        $_SESSION['success_message'] = "Income statement item added successfully!";
    }
    
    header("Location: settings.php");
    exit;
}

// Get existing items
$balance_sheet_items = $db->query("SELECT * FROM balance_sheet_items WHERE status = 'active' ORDER BY category, class, item_name")->fetchAll();
$cashflow_components = $db->query("SELECT * FROM cashflow_components WHERE status = 'active' ORDER BY category, component_name")->fetchAll();
$income_statement_items = $db->query("SELECT * FROM income_statement_items WHERE status = 'active' ORDER BY item_type, category, item_name")->fetchAll();

$page_title = 'Financial Settings';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Financial Statement Settings</h4>
                    <p class="mb-0">Configure components for financial reports</p>
                </div>
                <div class="card-body">
                    
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>

                    <!-- Balance Sheet Configuration -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-balance-scale me-2"></i>Balance Sheet Configuration</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Category</label>
                                        <select class="form-select" name="category" required>
                                            <option value="">Select Category</option>
                                            <option value="asset">Assets</option>
                                            <option value="liability">Liabilities</option>
                                            <option value="equity">Equity</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Class</label>
                                        <select class="form-select" name="class" required>
                                            <option value="">Select Class</option>
                                            <option value="current">Current</option>
                                            <option value="non_current">Non-Current</option>
                                            <option value="owner_capital">Owner's Capital</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Item Name</label>
                                        <input type="text" class="form-control" name="item_name" placeholder="e.g., Cash, Accounts Receivable" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Account Code</label>
                                        <input type="text" class="form-control" name="account_code" placeholder="e.g., 1001, 2001">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" rows="2" placeholder="Item description..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" name="add_balance_sheet_item" class="btn btn-primary">
                                            <i class="bi bi-plus-circle me-1"></i>Add Balance Sheet Item
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <h6>Existing Balance Sheet Items</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Class</th>
                                            <th>Item Name</th>
                                            <th>Account Code</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($balance_sheet_items as $item): ?>
                                            <tr>
                                                <td><span class="badge bg-info"><?php echo ucfirst($item['category']); ?></span></td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo $item['class'] === 'current' ? 'Current' : 
                                                              ($item['class'] === 'non_current' ? 'Non-Current' : 'Owner\'s Capital'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                <td><?php echo htmlspecialchars($item['account_code']); ?></td>
                                                <td><?php echo htmlspecialchars($item['description']); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Flow Statement Configuration -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Cash Flow Statement Configuration</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Category</label>
                                        <select class="form-select" name="cashflow_category" required>
                                            <option value="">Select Category</option>
                                            <option value="operating">Operating Activities</option>
                                            <option value="investing">Investing Activities</option>
                                            <option value="financing">Financing Activities</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Component Name</label>
                                        <input type="text" class="form-control" name="component_name" placeholder="e.g., Cash from Customers" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Calculation Basis</label>
                                        <select class="form-select" name="calculation_basis">
                                            <option value="direct">Direct Method</option>
                                            <option value="indirect">Indirect Method</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="cashflow_description" rows="2" placeholder="Component description..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" name="add_cashflow_component" class="btn btn-success">
                                            <i class="bi bi-plus-circle me-1"></i>Add Cash Flow Component
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <h6>Existing Cash Flow Components</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Component Name</th>
                                            <th>Calculation Basis</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cashflow_components as $component): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-success">
                                                        <?php echo $component['category'] === 'operating' ? 'Operating' : 
                                                              ($component['category'] === 'investing' ? 'Investing' : 'Financing'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($component['component_name']); ?></td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo ucfirst($component['calculation_basis']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($component['description']); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Income Statement Configuration -->
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Income Statement Configuration</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Item Type</label>
                                        <select class="form-select" name="item_type" required>
                                            <option value="">Select Type</option>
                                            <option value="revenue">Revenue</option>
                                            <option value="expense">Expense</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Category</label>
                                        <input type="text" class="form-control" name="income_category" placeholder="e.g., Sales, Cost of Goods" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Item Name</label>
                                        <input type="text" class="form-control" name="income_item_name" placeholder="e.g., Product Sales, Rent Expense" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="income_description" rows="2" placeholder="Item description..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" name="add_income_statement_item" class="btn btn-warning">
                                            <i class="bi bi-plus-circle me-1"></i>Add Income Statement Item
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <h6>Existing Income Statement Items</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Item Name</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($income_statement_items as $item): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?php echo $item['item_type'] === 'revenue' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo ucfirst($item['item_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['category']); ?></td>
                                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                <td><?php echo htmlspecialchars($item['description']); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>