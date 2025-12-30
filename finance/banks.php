<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_finance_officer();

$db = getDBConnection();

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_bank_account'])) {
        // Create new bank account
        try {
            $stmt = $db->prepare("
                INSERT INTO banks_accounts (
                    code, account_name, account_number, bank_name, 
                    account_type, currency, branch_name, bank_code,
                    current_balance, available_balance,
                    description, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Safely get code value with null coalescing
            $code = !empty($_POST['code']) ? trim($_POST['code']) : null;
            
            $success = $stmt->execute([
                $code,
                trim($_POST['account_name']),
                trim($_POST['account_number']),
                trim($_POST['bank_name']),
                $_POST['account_type'],
                $_POST['currency'],
                trim($_POST['branch_name'] ?? ''),
                trim($_POST['bank_code'] ?? ''),
                floatval($_POST['current_balance'] ?? 0),
                floatval($_POST['available_balance'] ?? 0),
                trim($_POST['description'] ?? ''),
                'active'
            ]);
            
            if ($success) {
                $message = 'Bank account created successfully!';
                $message_type = 'success';
            } else {
                $message = 'Failed to create bank account.';
                $message_type = 'danger';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } elseif (isset($_POST['update_bank_account'])) {
        // Update bank account
        try {
            $stmt = $db->prepare("
                UPDATE banks_accounts SET
                    code = ?,
                    account_name = ?,
                    account_number = ?,
                    bank_name = ?,
                    account_type = ?,
                    currency = ?,
                    branch_name = ?,
                    bank_code = ?,
                    current_balance = ?,
                    available_balance = ?,
                    description = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            // Safely get code value with null coalescing
            $code = !empty($_POST['code']) ? trim($_POST['code']) : null;
            
            $success = $stmt->execute([
                $code,
                trim($_POST['account_name']),
                trim($_POST['account_number']),
                trim($_POST['bank_name']),
                $_POST['account_type'],
                $_POST['currency'],
                trim($_POST['branch_name'] ?? ''),
                trim($_POST['bank_code'] ?? ''),
                floatval($_POST['current_balance'] ?? 0),
                floatval($_POST['available_balance'] ?? 0),
                trim($_POST['description'] ?? ''),
                $_POST['id']
            ]);
            
            if ($success) {
                $message = 'Bank account updated successfully!';
                $message_type = 'success';
            } else {
                $message = 'Failed to update bank account.';
                $message_type = 'danger';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } elseif (isset($_POST['delete_bank_account'])) {
        // Soft delete bank account
        try {
            $stmt = $db->prepare("
                UPDATE banks_accounts SET 
                    status = 'inactive',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $success = $stmt->execute([$_POST['id']]);
            
            if ($success) {
                $message = 'Bank account deleted successfully!';
                $message_type = 'success';
            } else {
                $message = 'Failed to delete bank account.';
                $message_type = 'danger';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Get all bank accounts with GL account names using COLLATE to fix collation issue
$bank_accounts_stmt = $db->prepare("
    SELECT 
        ba.*,
        coa.account_name as gl_account_name
    FROM banks_accounts ba
    LEFT JOIN chart_of_accounts coa ON coa.account_code COLLATE utf8mb4_general_ci = ba.code
    WHERE ba.status = 'active'
    ORDER BY ba.bank_name, ba.account_name
");
$bank_accounts_stmt->execute();
$bank_accounts = $bank_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all GL accounts for dropdown - show only asset accounts (111x series)
$gl_accounts_stmt = $db->prepare("
    SELECT account_code, account_name 
    FROM chart_of_accounts 
    WHERE status = 'active'
    AND account_type = 'asset'
    AND (account_code LIKE '111%' OR account_code LIKE '112%' OR account_code LIKE '114%')
    AND is_group_account = 0
    ORDER BY account_code
");
$gl_accounts_stmt->execute();
$gl_accounts = $gl_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get bank account types
$account_types = ['savings', 'checking', 'current', 'fixed_deposit', 'business', 'personal'];

// Get currencies
$currencies = ['TSH', 'USD', 'EUR', 'GBP'];

$page_title = 'Bank Accounts Management';
include '../includes/header.php';
?>

<style>
/* Clean, professional styling */
.card {
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    padding: 15px 20px;
    font-weight: 600;
}

.card-body {
    padding: 20px;
}

.table {
    font-size: 0.9rem;
    margin-bottom: 0;
}

.table th {
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
    background-color: #f8f9fa;
    padding: 12px 15px;
}

.table td {
    padding: 12px 15px;
    border-top: 1px solid #f0f0f0;
    vertical-align: middle;
}

.table-hover tbody tr:hover {
    background-color: #f8f9fa;
}

.badge {
    padding: 4px 8px;
    font-size: 0.75rem;
    font-weight: 500;
}

.bg-success { background-color: #28a745 !important; }
.bg-danger { background-color: #dc3545 !important; }
.bg-warning { background-color: #ffc107 !important; color: #212529 !important; }
.bg-info { background-color: #17a2b8 !important; }
.bg-secondary { background-color: #6c757d !important; }

.btn {
    border-radius: 4px;
    padding: 6px 12px;
    font-size: 0.875rem;
    font-weight: 500;
}

.btn-sm {
    padding: 4px 8px;
    font-size: 0.8125rem;
}

.btn-primary {
    background-color: #007bff;
    border-color: #007bff;
}

.btn-outline-primary {
    color: #007bff;
    border-color: #007bff;
}

.btn-outline-primary:hover {
    background-color: #007bff;
    color: white;
}

.btn-outline-secondary {
    color: #6c757d;
    border-color: #6c757d;
}

.btn-outline-secondary:hover {
    background-color: #6c757d;
    color: white;
}

.btn-outline-danger {
    color: #dc3545;
    border-color: #dc3545;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    color: white;
}

.form-control, .form-select {
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 0.9rem;
}

.form-control:focus, .form-select:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
}

.form-label {
    font-weight: 500;
    margin-bottom: 6px;
    font-size: 0.9rem;
}

.required::after {
    content: ' *';
    color: #dc3545;
}

.alert {
    border-radius: 4px;
    padding: 12px 15px;
    margin-bottom: 15px;
    border: 1px solid transparent;
}

.alert-success {
    background-color: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}

.alert-danger {
    background-color: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}

.alert-info {
    background-color: #d1ecf1;
    border-color: #bee5eb;
    color: #0c5460;
}

.modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    padding: 15px 20px;
}

.modal-title {
    font-weight: 600;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #e0e0e0;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
}

.status-inactive {
    background-color: #f8d7da;
    color: #721c24;
}

.currency-badge {
    display: inline-block;
    padding: 2px 6px;
    background-color: #e9ecef;
    border-radius: 3px;
    font-size: 0.75rem;
    font-family: monospace;
}

.account-code {
    font-family: monospace;
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.85rem;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    opacity: 0.3;
}

.form-section {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    border-left: 4px solid #007bff;
}

.form-section h6 {
    margin-bottom: 15px;
    color: #007bff;
}

.gl-link-info {
    background-color: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 10px 15px;
    margin: 10px 0;
    border-radius: 4px;
    font-size: 0.875rem;
}

.optional-field {
    color: #6c757d;
    font-style: italic;
}

.is-invalid {
    border-color: #dc3545 !important;
}

.invalid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #dc3545;
}
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Bank Accounts Management</h2>
                    <p class="text-muted mb-0">Create and manage bank accounts</p>
                </div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="bi bi-plus-circle"></i> Add Bank Account
                </button>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bank Accounts List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-bank"></i> Bank Accounts
                <span class="badge bg-secondary ms-2"><?php echo count($bank_accounts); ?></span>
            </h6>
            <div class="text-muted">
                <small>Active accounts only</small>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($bank_accounts)): ?>
                <div class="empty-state">
                    <i class="bi bi-bank"></i>
                    <h5 class="mb-2">No Bank Accounts Found</h5>
                    <p class="mb-0">Click "Add Bank Account" to create your first bank account.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Bank</th>
                                <th>Account Name</th>
                                <th>Account Number</th>
                                <th>Type</th>
                                <th>GL Account</th>
                                <th class="text-end">Current Balance</th>
                                <th class="text-end">Available Balance</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bank_accounts as $account): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($account['bank_name']); ?></strong>
                                        <?php if ($account['branch_name']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($account['branch_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td>
                                        <span class="account-code"><?php echo htmlspecialchars($account['account_number']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $account['account_type']))); ?>
                                        </span>
                                        <br>
                                        <small class="currency-badge"><?php echo htmlspecialchars($account['currency']); ?></small>
                                    </td>
                                    <td>
                                        <div>
                                            <?php if (!empty($account['code']) && !empty($account['gl_account_name'])): ?>
                                                <span class="account-code"><?php echo htmlspecialchars($account['code']); ?></span>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($account['gl_account_name']); ?></small>
                                            <?php elseif (!empty($account['code'])): ?>
                                                <span class="account-code"><?php echo htmlspecialchars($account['code']); ?></span>
                                                <br>
                                                <small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Code not found in GL</small>
                                            <?php else: ?>
                                                <span class="text-muted optional-field">Not linked</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo htmlspecialchars($account['currency']); ?> 
                                        <?php echo number_format($account['current_balance'], 2); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <?php echo htmlspecialchars($account['currency']); ?> 
                                        <?php echo number_format($account['available_balance'], 2); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-active">Active</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    onclick="editBankAccount(<?php echo htmlspecialchars(json_encode($account)); ?>)"
                                                    data-bs-toggle="modal" data-bs-target="#createModal">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars(addslashes($account['bank_name'] . ' - ' . $account['account_name'])); ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- GL Account Reference -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-diagram-3"></i> GL Accounts Reference (Optional)</h6>
        </div>
        <div class="card-body">
            <div class="gl-link-info mb-3">
                <i class="bi bi-info-circle"></i> 
                <strong>Note:</strong> Linking to a GL account is optional but recommended for reconciliation.
                If you link a bank account to a GL account, make sure the code exists in your Chart of Accounts.
            </div>
            
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Recommended GL Account Code</th>
                            <th>Account Name</th>
                            <th>For Bank Account Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gl_accounts as $account): ?>
                            <tr>
                                <td><span class="account-code"><?php echo htmlspecialchars($account['account_code']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($account['account_name']); ?></strong></td>
                                <td>
                                    <?php 
                                    $recommended_for = '';
                                    if ($account['account_code'] == '1111') $recommended_for = 'Petty Cash / Physical cash';
                                    elseif ($account['account_code'] == '1112') $recommended_for = 'Bank accounts (savings, checking, current)';
                                    elseif ($account['account_code'] == '1113') $recommended_for = 'Mobile money accounts';
                                    elseif ($account['account_code'] == '1114') $recommended_for = 'Fixed deposits, treasury bills';
                                    elseif ($account['account_code'] == '1121') $recommended_for = 'Trade receivables';
                                    elseif ($account['account_code'] == '1144') $recommended_for = 'DSE clearing accounts';
                                    elseif ($account['account_code'] == '1145') $recommended_for = 'CSDR settlement accounts';
                                    else $recommended_for = 'Various current assets';
                                    echo htmlspecialchars($recommended_for);
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-3">
                <h6>How to Link Bank Accounts (Optional):</h6>
                <ol class="mb-0">
                    <li>Select a GL account code from the dropdown (or leave blank)</li>
                    <li>Most bank accounts use <strong>1112 - Cash at Bank</strong></li>
                    <li>Fixed deposits use <strong>1114 - Short-term Treasury Bills</strong></li>
                    <li>Physical cash uses <strong>1111 - Cash on Hand</strong></li>
                    <li>If no code is selected, reconciliation will still work but may be less accurate</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Bank Account Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add New Bank Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="bankAccountForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="accountId">
                    
                    <div class="form-section">
                        <h6>Basic Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="bank_name" class="form-label required">Bank Name</label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name" required>
                                <div class="invalid-feedback">Please enter bank name.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="account_name" class="form-label required">Account Name</label>
                                <input type="text" class="form-control" id="account_name" name="account_name" required>
                                <div class="invalid-feedback">Please enter account name.</div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="account_number" class="form-label required">Account Number</label>
                                <input type="text" class="form-control" id="account_number" name="account_number" required>
                                <div class="invalid-feedback">Please enter account number.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="bank_code" class="form-label">Bank Code <span class="optional-field">(Optional)</span></label>
                                <input type="text" class="form-control" id="bank_code" name="bank_code">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h6>Account Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="account_type" class="form-label required">Account Type</label>
                                <select class="form-select" id="account_type" name="account_type" required>
                                    <option value="">Select Type</option>
                                    <?php foreach ($account_types as $type): ?>
                                        <option value="<?php echo $type; ?>"><?php echo ucfirst(str_replace('_', ' ', $type)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select account type.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="currency" class="form-label required">Currency</label>
                                <select class="form-select" id="currency" name="currency" required>
                                    <option value="">Select Currency</option>
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo $currency; ?>"><?php echo $currency; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select currency.</div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="branch_name" class="form-label">Branch Name <span class="optional-field">(Optional)</span></label>
                                <input type="text" class="form-control" id="branch_name" name="branch_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="code" class="form-label">GL Account Code <span class="optional-field">(Optional)</span></label>
                                <select class="form-select" id="code" name="code">
                                    <option value="">-- No GL Account Link --</option>
                                    <?php foreach ($gl_accounts as $account): ?>
                                        <option value="<?php echo htmlspecialchars($account['account_code']); ?>">
                                            <?php echo htmlspecialchars($account['account_code']); ?> - <?php echo htmlspecialchars($account['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Links bank account to Chart of Accounts for reconciliation</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h6>Balances</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="current_balance" class="form-label">Current Balance <span class="optional-field">(Optional)</span></label>
                                <input type="number" class="form-control" id="current_balance" name="current_balance" step="0.01" value="0.00">
                                <div class="invalid-feedback">Please enter a valid number.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="available_balance" class="form-label">Available Balance <span class="optional-field">(Optional)</span></label>
                                <input type="number" class="form-control" id="available_balance" name="available_balance" step="0.01" value="0.00">
                                <div class="invalid-feedback">Please enter a valid number.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description <span class="optional-field">(Optional)</span></label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Optional description of the bank account"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="create_bank_account" id="submitBtn">Create Bank Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteAccountName"></strong>?</p>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Note:</strong> This will mark the account as inactive. The record will be kept but hidden from active lists.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="id" id="deleteId">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" name="delete_bank_account">Delete Account</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editBankAccount(account) {
    // Update modal title and submit button
    document.getElementById('modalTitle').textContent = 'Edit Bank Account';
    document.getElementById('submitBtn').textContent = 'Update Bank Account';
    document.getElementById('submitBtn').name = 'update_bank_account';
    
    // Populate form fields
    document.getElementById('accountId').value = account.id;
    document.getElementById('bank_name').value = account.bank_name || '';
    document.getElementById('account_name').value = account.account_name || '';
    document.getElementById('account_number').value = account.account_number || '';
    document.getElementById('bank_code').value = account.bank_code || '';
    document.getElementById('account_type').value = account.account_type || '';
    document.getElementById('currency').value = account.currency || '';
    document.getElementById('branch_name').value = account.branch_name || '';
    document.getElementById('code').value = account.code || '';
    document.getElementById('current_balance').value = parseFloat(account.current_balance || 0).toFixed(2);
    document.getElementById('available_balance').value = parseFloat(account.available_balance || 0).toFixed(2);
    document.getElementById('description').value = account.description || '';
}

function resetForm() {
    // Reset form to create mode
    document.getElementById('modalTitle').textContent = 'Add New Bank Account';
    document.getElementById('submitBtn').textContent = 'Create Bank Account';
    document.getElementById('submitBtn').name = 'create_bank_account';
    document.getElementById('bankAccountForm').reset();
    document.getElementById('accountId').value = '';
    
    // Clear validation states
    const invalidElements = document.querySelectorAll('.is-invalid');
    invalidElements.forEach(el => el.classList.remove('is-invalid'));
}

function confirmDelete(id, accountName) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteAccountName').textContent = accountName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Reset form when modal is shown for creating new account
document.getElementById('createModal').addEventListener('show.bs.modal', function (event) {
    if (!event.relatedTarget || event.relatedTarget.textContent.includes('Add Bank Account')) {
        resetForm();
    }
});

// Auto-suggest GL account code based on account type (optional feature)
document.getElementById('account_type').addEventListener('change', function() {
    const glCodeSelect = document.getElementById('code');
    const accountType = this.value;
    
    // Clear any existing value
    glCodeSelect.value = '';
    
    // Suggest based on account type (optional feature)
    let suggestedCode = '';
    if (accountType === 'fixed_deposit') {
        suggestedCode = '1114'; // Short-term Treasury Bills
    } else if (accountType === 'savings' || accountType === 'checking' || accountType === 'current') {
        suggestedCode = '1112'; // Cash at Bank
    }
    
    // Try to set the suggested value
    if (suggestedCode) {
        for (let i = 0; i < glCodeSelect.options.length; i++) {
            if (glCodeSelect.options[i].value === suggestedCode) {
                glCodeSelect.value = suggestedCode;
                break;
            }
        }
    }
});

// Form validation
document.getElementById('bankAccountForm').addEventListener('submit', function(event) {
    let isValid = true;
    
    // Clear previous validation
    const invalidElements = document.querySelectorAll('.is-invalid');
    invalidElements.forEach(el => el.classList.remove('is-invalid'));
    
    // Validate required fields
    const requiredFields = [
        'bank_name', 'account_name', 'account_number', 'account_type', 'currency'
    ];
    
    requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    // Validate numeric fields
    const numericFields = ['current_balance', 'available_balance'];
    numericFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field.value && isNaN(parseFloat(field.value))) {
            field.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    if (!isValid) {
        event.preventDefault();
        alert('Please fill in all required fields correctly.');
    }
});

// Show success message and reload if form was submitted successfully
<?php if ($message && $message_type == 'success'): ?>
setTimeout(function() {
    window.location.reload();
}, 1500);
<?php endif; ?>

// Auto-copy current balance to available balance
document.getElementById('current_balance').addEventListener('input', function() {
    const currentBalance = parseFloat(this.value) || 0;
    const availableBalanceField = document.getElementById('available_balance');
    
    // Only auto-fill if available balance is 0 or empty
    if (!availableBalanceField.value || parseFloat(availableBalanceField.value) === 0) {
        availableBalanceField.value = currentBalance.toFixed(2);
    }
});

// Format numbers on blur
document.getElementById('current_balance').addEventListener('blur', function() {
    if (this.value) {
        this.value = parseFloat(this.value || 0).toFixed(2);
    }
});

document.getElementById('available_balance').addEventListener('blur', function() {
    if (this.value) {
        this.value = parseFloat(this.value || 0).toFixed(2);
    }
});
</script>

<?php include '../includes/footer.php'; ?>