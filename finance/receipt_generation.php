<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Fetch payment methods
try {
    $payment_methods_stmt = $db->query("SELECT id, method_name, description FROM payment_methods WHERE status = 'active' ORDER BY method_name");
    $payment_methods = $payment_methods_stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
    $error_message = "Error fetching payment methods: " . $e->getMessage();
}

// Fetch ledger types
try {
    $ledger_types_stmt = $db->query("SELECT code, description FROM ledger_types WHERE status = 'active' ORDER BY description");
    $ledger_types = $ledger_types_stmt->fetchAll();
} catch (PDOException $e) {
    $ledger_types = [];
    $error_message = "Error fetching ledger types: " . $e->getMessage();
}

// Fetch bank accounts
try {
    $bank_accounts_stmt = $db->query("SELECT id, account_number, account_name, bank_name FROM banks_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $bank_accounts = [];
    $error_message = "Error fetching bank accounts: " . $e->getMessage();
}

// Handle AJAX request for fetching names based on ledger type
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_names') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $names = [];
    
    try {
        switch ($ledger_type) {
            case 'A': // Agent
                $stmt = $db->query("SELECT id, name FROM agents WHERE status = 'active' ORDER BY name");
                $names = $stmt->fetchAll();
                break;
            case 'B': // Broker
                $stmt = $db->query("SELECT id, broker_name as name FROM brokers WHERE status = 'active' ORDER BY broker_name");
                $names = $stmt->fetchAll();
                break;
            case 'C': // Supplier
                $stmt = $db->query("SELECT id, supplier_name as name FROM suppliers WHERE status = 'active' ORDER BY supplier_name");
                $names = $stmt->fetchAll();
                break;
            case 'D': // Customer
                $stmt = $db->query("SELECT id, client_name as name FROM clients WHERE status = 'active' ORDER BY client_name");
                $names = $stmt->fetchAll();
                break;
           case 'O': // Nominal Client (Chart of Accounts)
                $stmt = $db->query("SELECT account_code as id, item_name as name FROM balance_sheet_items WHERE status = 'active' ORDER BY item_name");
                $names = $stmt->fetchAll();
                break;
            case 'U': // Custodian
                $stmt = $db->query("SELECT id, custodian_name as name FROM custodians WHERE status = 'active' ORDER BY custodian_name");
                $names = $stmt->fetchAll();
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($names);
        exit;
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

// Handle receipt generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_receipt'])) {
    $receipt_date = $_POST['receipt_date'];
    $payment_mode = $_POST['payment_mode'];
    $account_of = $_POST['account_of'];
    $name_id = $_POST['name'];
    $ac_debit = $_POST['ac_debit'];
    $receipt_no = $_POST['receipt_no'];
    $currency = $_POST['currency'];
    $account_no = $_POST['account_no'];
    $amount = $_POST['amount'];
    $cheque_no = $_POST['cheque_no'];
    $balance = $_POST['balance'];
    $narration = $_POST['narration'];
    
    // Validation
    if (empty($receipt_date) || empty($payment_mode) || empty($account_of) || empty($name_id) || 
        empty($ac_debit) || empty($receipt_no) || empty($currency) || empty($amount)) {
        $error_message = 'Please fill all required fields.';
    } elseif ($amount <= 0) {
        $error_message = 'Amount must be greater than zero.';
    } else {
        try {
            // Generate receipt number if not provided
            if (empty($receipt_no)) {
                $receipt_no = 'RCP' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            }
            
            // Get ledger type description
            $ledger_desc = '';
            foreach ($ledger_types as $type) {
                if ($type['code'] == $account_of) {
                    $ledger_desc = $type['description'];
                    break;
                }
            }
            
            // Get name based on ledger type
            $name = '';
            switch ($account_of) {
                case 'A': // Agent
                    $stmt = $db->prepare("SELECT name FROM agents WHERE id = ?");
                    $stmt->execute([$name_id]);
                    $result = $stmt->fetch();
                    $name = $result['name'] ?? '';
                    break;
                case 'B': // Broker
                    $stmt = $db->prepare("SELECT broker_name FROM brokers WHERE id = ?");
                    $stmt->execute([$name_id]);
                    $result = $stmt->fetch();
                    $name = $result['broker_name'] ?? '';
                    break;
                case 'C': // Supplier
                    $stmt = $db->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
                    $stmt->execute([$name_id]);
                    $result = $stmt->fetch();
                    $name = $result['supplier_name'] ?? '';
                    break;
                case 'D': // Customer
                    $stmt = $db->prepare("SELECT client_name FROM clients WHERE id = ?");
                    $stmt->execute([$name_id]);
                    $result = $stmt->fetch();
                    $name = $result['client_name'] ?? '';
                    break;
              case 'O': // Nominal Client
                        $stmt = $db->prepare("SELECT item_name FROM balance_sheet_items WHERE account_code = ?");
                        $stmt->execute([$name_id]);
                        $result = $stmt->fetch();
                        $name = $result['item_name'] ?? '';
                        break;
                case 'U': // Custodian
                    $stmt = $db->prepare("SELECT custodian_name FROM custodians WHERE id = ?");
                    $stmt->execute([$name_id]);
                    $result = $stmt->fetch();
                    $name = $result['custodian_name'] ?? '';
                    break;
            }
            
            // Get payment method name
            $payment_method_name = '';
            foreach ($payment_methods as $method) {
                if ($method['id'] == $payment_mode) {
                    $payment_method_name = $method['method_name'];
                    break;
                }
            }
            
            // Get bank account details
            $bank_details = '';
            foreach ($bank_accounts as $bank) {
                if ($bank['id'] == $ac_debit) {
                    $bank_details = $bank['bank_name'] . ' - ' . $bank['account_number'];
                    break;
                }
            }
            
            // Insert receipt into database
            $stmt = $db->prepare("
                INSERT INTO receipts (
                    receipt_date, payment_mode, account_of, name_id, name, ac_debit, 
                    receipt_no, currency, account_no, amount, cheque_no, balance, 
                    narration, created_by, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            
            $stmt->execute([
                $receipt_date, $payment_mode, $account_of, $name_id, $name, $ac_debit,
                $receipt_no, $currency, $account_no, $amount, $cheque_no, $balance,
                $narration, $_SESSION['user_id']
            ]);
            
            $receipt_id = $db->lastInsertId();
            
            $success_message = "Receipt generated successfully! Receipt Number: " . $receipt_no;
            
            // Clear form on success
            $_POST = [];
            
        } catch (PDOException $e) {
            $error_message = "Error generating receipt: " . $e->getMessage();
        }
    }
}

$page_title = 'Receipt Generation';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);">
                            <i class="bi bi-receipt text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Receipt Generation</h1>
                        <p class="page-subtitle">Create and manage financial receipts</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Accountant Portal</span>
                    <span class="fw-semibold">NEOVAM</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Generate New Receipt</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="receiptForm">
                        <div class="row g-3">
                            <!-- Receipt Basic Information -->
                            <div class="col-md-6">
                                <label class="form-label">Receipt Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="receipt_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Mode <span class="text-danger">*</span></label>
                                <select class="form-select" name="payment_mode" required>
                                    <option value="">Select Payment Mode</option>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?php echo $method['id']; ?>" <?php echo ($_POST['payment_mode'] ?? '') == $method['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($method['method_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Account Information -->
                            <div class="col-md-6">
                                <label class="form-label">Account Of <span class="text-danger">*</span></label>
                                <select class="form-select" name="account_of" id="account_of" required>
                                    <option value="">Select Account Type</option>
                                    <?php foreach ($ledger_types as $type): ?>
                                        <option value="<?php echo $type['code']; ?>" <?php echo ($_POST['account_of'] ?? '') == $type['code'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <select class="form-select" name="name" id="name_select" required>
                                    <option value="">Select Account Type First</option>
                                </select>
                            </div>

                            <!-- Bank and Receipt Details -->
                            <div class="col-md-6">
                                <label class="form-label">A/C Debit <span class="text-danger">*</span></label>
                                <select class="form-select" name="ac_debit" required>
                                    <option value="">Select Bank Account</option>
                                    <?php foreach ($bank_accounts as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>" <?php echo ($_POST['ac_debit'] ?? '') == $bank['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Receipt No:</label>
                                <input type="text" class="form-control" name="receipt_no" value="<?php echo $_POST['receipt_no'] ?? ''; ?>" placeholder="Auto-generated if empty">
                            </div>

                            <!-- Currency and Account Details -->
                            <div class="col-md-4">
                                <label class="form-label">Currency <span class="text-danger">*</span></label>
                                <select class="form-select" name="currency" required>
                                    <option value="">Select Currency</option>
                                    <option value="Tsh" <?php echo ($_POST['currency'] ?? '') == 'Tsh' ? 'selected' : ''; ?>>Tsh (Tanzanian Shilling)</option>
                                    <option value="Ksh" <?php echo ($_POST['currency'] ?? '') == 'Ksh' ? 'selected' : ''; ?>>Ksh (Kenyan Shilling)</option>
                                    <option value="USD" <?php echo ($_POST['currency'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD (US Dollar)</option>
                                    <option value="UGsh" <?php echo ($_POST['currency'] ?? '') == 'UGsh' ? 'selected' : ''; ?>>UGsh (Ugandan Shilling)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Account No</label>
                                <input type="text" class="form-control" name="account_no" value="<?php echo $_POST['account_no'] ?? ''; ?>" placeholder="Account number">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="amount" value="<?php echo $_POST['amount'] ?? ''; ?>" step="0.01" min="0.01" required placeholder="0.00">
                            </div>

                            <!-- Additional Details -->
                            <div class="col-md-4">
                                <label class="form-label">Cheque No</label>
                                <input type="text" class="form-control" name="cheque_no" value="<?php echo $_POST['cheque_no'] ?? ''; ?>" placeholder="If applicable">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Balance</label>
                                <input type="number" class="form-control" name="balance" value="<?php echo $_POST['balance'] ?? ''; ?>" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Narration</label>
                                <input type="text" class="form-control" name="narration" value="<?php echo $_POST['narration'] ?? ''; ?>" placeholder="Transaction description">
                            </div>

                            <!-- Submit Button -->
                            <div class="col-12">
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="reset" class="btn btn-outline-secondary me-md-2">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Reset Form
                                    </button>
                                    <button type="submit" name="generate_receipt" class="btn btn-success">
                                        <i class="bi bi-receipt me-1"></i>Generate Receipt
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Receipts Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Receipts</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Receipt No</th>
                                    <th>Date</th>
                                    <th>Account Of</th>
                                    <th>Name</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $recent_receipts = $db->query("
                                        SELECT receipt_no, receipt_date, account_of, name, amount, currency, status 
                                        FROM receipts 
                                        ORDER BY created_at DESC 
                                        LIMIT 10
                                    ")->fetchAll();
                                    
                                    if (empty($recent_receipts)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox display-4"></i>
                                                <p class="mt-2">No receipts generated yet</p>
                                            </td>
                                        </tr>
                                    <?php else:
                                        foreach ($recent_receipts as $receipt): ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($receipt['receipt_no']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($receipt['receipt_date'])); ?></td>
                                                <td>
                                                    <?php 
                                                    $account_desc = '';
                                                    foreach ($ledger_types as $type) {
                                                        if ($type['code'] == $receipt['account_of']) {
                                                            $account_desc = $type['description'];
                                                            break;
                                                        }
                                                    }
                                                    echo htmlspecialchars($account_desc);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($receipt['name']); ?></td>
                                                <td class="fw-bold text-success"><?php echo number_format($receipt['amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($receipt['currency']); ?></td>
                                                <td>
                                                    <span class="badge bg-success">Active</span>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                    endif;
                                } catch (PDOException $e) {
                                    echo '<tr><td colspan="7" class="text-center text-muted">Error loading recent receipts</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accountOfSelect = document.getElementById('account_of');
    const nameSelect = document.getElementById('name_select');

    // Function to load names based on selected ledger type
    function loadNames(ledgerType) {
        if (!ledgerType) {
            nameSelect.innerHTML = '<option value="">Select Account Type First</option>';
            return;
        }

        // Show loading
        nameSelect.innerHTML = '<option value="">Loading...</option>';
        
        // Fetch names via AJAX
        fetch(`?ajax=get_names&ledger_type=${ledgerType}`)
            .then(response => response.json())
            .then(data => {
                nameSelect.innerHTML = '<option value="">Select Name</option>';
                data.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = item.name;
                    nameSelect.appendChild(option);
                });
            })
            .catch(error => {
                console.error('Error loading names:', error);
                nameSelect.innerHTML = '<option value="">Error loading names</option>';
            });
    }

    // Event listener for account type change
    accountOfSelect.addEventListener('change', function() {
        loadNames(this.value);
    });

    // Load names if account type is already selected (form submission with error)
    <?php if (!empty($_POST['account_of'])): ?>
        loadNames('<?php echo $_POST['account_of']; ?>');
        // Set the selected name if it exists
        setTimeout(() => {
            nameSelect.value = '<?php echo $_POST['name'] ?? ''; ?>';
        }, 500);
    <?php endif; ?>

    // Auto-generate receipt number if empty
    const receiptNoInput = document.querySelector('input[name="receipt_no"]');
    const receiptDateInput = document.querySelector('input[name="receipt_date"]');
    
    function generateReceiptNo() {
        if (!receiptNoInput.value) {
            const date = receiptDateInput.value.replace(/-/g, '');
            const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            receiptNoInput.value = `RCP${date}${random}`;
        }
    }

    receiptDateInput.addEventListener('change', generateReceiptNo);
    
    // Generate receipt number on page load if date is set
    if (receiptDateInput.value && !receiptNoInput.value) {
        generateReceiptNo();
    }
});
</script>

<?php include '../includes/footer.php'; ?>