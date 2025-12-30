<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin_or_trader();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_broker'])) {
        $broker_code = sanitize_input($_POST['broker_code']);
        $broker_name = sanitize_input($_POST['broker_name']);
        $license_number = sanitize_input($_POST['license_number']);
        $contact_person = sanitize_input($_POST['contact_person']);
        $phone = sanitize_input($_POST['phone']);
        $email = sanitize_input($_POST['email']);
        $address = sanitize_input($_POST['address']);
        
        try {
            $stmt = $db->prepare("
                INSERT INTO brokers (broker_code, broker_name, license_number, contact_person, phone, email, address) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$broker_code, $broker_name, $license_number, $contact_person, $phone, $email, $address])) {
                $success_message = 'Broker added successfully.';
            } else {
                $error_message = 'Error adding broker.';
            }
        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['add_custodian'])) {
        $custodian_code = sanitize_input($_POST['custodian_code']);
        $custodian_name = sanitize_input($_POST['custodian_name']);
        $license_number = sanitize_input($_POST['license_number']);
        $contact_person = sanitize_input($_POST['contact_person']);
        $phone = sanitize_input($_POST['phone']);
        $email = sanitize_input($_POST['email']);
        $address = sanitize_input($_POST['address']);
        
        try {
            $stmt = $db->prepare("
                INSERT INTO custodians (custodian_code, custodian_name, license_number, contact_person, phone, email, address) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$custodian_code, $custodian_name, $license_number, $contact_person, $phone, $email, $address])) {
                $success_message = 'Custodian added successfully.';
            } else {
                $error_message = 'Error adding custodian.';
            }
        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['add_bond'])) {
        $security_id = sanitize_input($_POST['security_id']);
        $bond_name = sanitize_input($_POST['bond_name']);
        $issuer = sanitize_input($_POST['issuer']);
        $coupon_rate = (float)$_POST['coupon_rate'];
        $face_value = (float)$_POST['face_value'];
        $issue_date = $_POST['issue_date'];
        $maturity_date = $_POST['maturity_date'];
        $currency = sanitize_input($_POST['currency']);
        
        // Validate ATS format (675-15-T16-A1)
        if (!preg_match('/^\d+-\d+(\.\d+)?-T\d+-[A-Z0-9]+$/', $security_id)) {
            $error_message = 'Security ID must be in ATS format (e.g., 675-15-T16-A1)';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO bonds (security_id, bond_name, issuer, coupon_rate, face_value, issue_date, maturity_date, currency) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$security_id, $bond_name, $issuer, $coupon_rate, $face_value, $issue_date, $maturity_date, $currency])) {
                    $success_message = 'Bond added successfully.';
                } else {
                    $error_message = 'Error adding bond.';
                }
            } catch (Exception $e) {
                $error_message = 'Error: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['add_equity'])) {
        $security_id = sanitize_input($_POST['security_id']);
        $stock_name = sanitize_input($_POST['stock_name']);
        $company_name = sanitize_input($_POST['company_name']);
        $sector = sanitize_input($_POST['sector']);
        $current_price = (float)$_POST['current_price'];
        $par_value = (float)$_POST['par_value'];
        $market_cap = (float)$_POST['market_cap'];
        
        // Validate stock symbol format (2-6 uppercase letters)
        if (!preg_match('/^[A-Z]{2,6}$/', $security_id)) {
            $error_message = 'Security ID must be 2-6 uppercase letters (e.g., WVSL, VODA)';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO equities (security_id, stock_name, company_name, sector, current_price, par_value, market_cap) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$security_id, $stock_name, $company_name, $sector, $current_price, $par_value, $market_cap])) {
                    $success_message = 'Equity added successfully.';
                } else {
                    $error_message = 'Error adding equity.';
                }
            } catch (Exception $e) {
                $error_message = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// Get data for display
$brokers = $db->query("SELECT * FROM brokers ORDER BY broker_name")->fetchAll();
$custodians = $db->query("SELECT * FROM custodians ORDER BY custodian_name")->fetchAll();
$bonds = $db->query("SELECT * FROM bonds ORDER BY security_id")->fetchAll();
$equities = $db->query("SELECT * FROM equities ORDER BY security_id")->fetchAll();

$page_title = 'Manage Entities';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-building"></i> Manage Entities</h2>
        </div>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
    </div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<!-- Navigation Tabs -->
<ul class="nav nav-tabs mb-4" id="entityTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="brokers-tab" data-bs-toggle="tab" data-bs-target="#brokers" type="button">
            <i class="bi bi-briefcase"></i> Brokers (<?php echo count($brokers); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="custodians-tab" data-bs-toggle="tab" data-bs-target="#custodians" type="button">
            <i class="bi bi-shield-check"></i> Custodians (<?php echo count($custodians); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bonds-tab" data-bs-toggle="tab" data-bs-target="#bonds" type="button">
            <i class="bi bi-file-earmark-text"></i> Bonds (<?php echo count($bonds); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="equities-tab" data-bs-toggle="tab" data-bs-target="#equities" type="button">
            <i class="bi bi-graph-up"></i> Equities (<?php echo count($equities); ?>)
        </button>
    </li>
</ul>

<div class="tab-content" id="entityTabContent">
    <!-- Brokers Tab -->
    <div class="tab-pane fade show active" id="brokers" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Brokers Management</h5>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBrokerModal">
                <i class="bi bi-plus-lg"></i> Add Broker
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Broker Name</th>
                        <th>License</th>
                        <th>Contact</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($brokers as $broker): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($broker['broker_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($broker['broker_name']); ?></td>
                            <td><?php echo htmlspecialchars($broker['license_number']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($broker['contact_person']); ?><br>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($broker['phone']); ?> | 
                                    <?php echo htmlspecialchars($broker['email']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $broker['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $broker['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Custodians Tab -->
    <div class="tab-pane fade" id="custodians" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Custodians Management</h5>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustodianModal">
                <i class="bi bi-plus-lg"></i> Add Custodian
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Custodian Name</th>
                        <th>License</th>
                        <th>Contact</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($custodians as $custodian): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($custodian['custodian_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($custodian['custodian_name']); ?></td>
                            <td><?php echo htmlspecialchars($custodian['license_number']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($custodian['contact_person']); ?><br>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($custodian['phone']); ?> | 
                                    <?php echo htmlspecialchars($custodian['email']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $custodian['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $custodian['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Bonds Tab -->
    <div class="tab-pane fade" id="bonds" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Bonds Management</h5>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBondModal">
                <i class="bi bi-plus-lg"></i> Add Bond
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Security ID</th>
                        <th>Bond Name</th>
                        <th>Issuer</th>
                        <th>Coupon Rate</th>
                        <th>Maturity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bonds as $bond): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($bond['security_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bond['bond_name']); ?></td>
                            <td><?php echo htmlspecialchars($bond['issuer']); ?></td>
                            <td><?php echo number_format($bond['coupon_rate'], 2); ?>%</td>
                            <td><?php echo date('M d, Y', strtotime($bond['maturity_date'])); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $bond['status'] === 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($bond['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Equities Tab -->
    <div class="tab-pane fade" id="equities" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Equities Management</h5>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEquityModal">
                <i class="bi bi-plus-lg"></i> Add Equity
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Stock Name</th>
                        <th>Company</th>
                        <th>Sector</th>
                        <th>Current Price</th>
                        <th>Market Cap</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equities as $equity): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($equity['security_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($equity['stock_name']); ?></td>
                            <td><?php echo htmlspecialchars($equity['company_name']); ?></td>
                            <td><?php echo htmlspecialchars($equity['sector']); ?></td>
                            <td>$<?php echo format_currency($equity['current_price']); ?></td>
                            <td>$<?php echo format_currency($equity['market_cap']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $equity['status'] === 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($equity['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Broker Modal -->
<div class="modal fade" id="addBrokerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Broker</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="broker_code" class="form-label">Broker Code *</label>
                                <input type="text" class="form-control" id="broker_code" name="broker_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="broker_name" class="form-label">Broker Name *</label>
                                <input type="text" class="form-control" id="broker_name" name="broker_name" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="license_number" class="form-label">License Number</label>
                                <input type="text" class="form-control" id="license_number" name="license_number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_broker" class="btn btn-primary">Add Broker</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Custodian Modal -->
<div class="modal fade" id="addCustodianModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Custodian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="custodian_code" class="form-label">Custodian Code *</label>
                                <input type="text" class="form-control" id="custodian_code" name="custodian_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="custodian_name" class="form-label">Custodian Name *</label>
                                <input type="text" class="form-control" id="custodian_name" name="custodian_name" required>
                            </div>
                        </div>
                    </div>
                    <!-- Similar fields as broker modal -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_custodian" class="btn btn-primary">Add Custodian</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Bond Modal -->
<div class="modal fade" id="addBondModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Bond</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Security ID Format:</strong> Use ATS format like 675-15-T16-A1 
                        (Bond number-Coupon rate-Term-Auction number)
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="security_id" class="form-label">Security ID (ATS Format) *</label>
                                <input type="text" class="form-control" id="security_id" name="security_id" 
                                       placeholder="675-15-T16-A1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="bond_name" class="form-label">Bond Name *</label>
                                <input type="text" class="form-control" id="bond_name" name="bond_name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="issuer" class="form-label">Issuer *</label>
                                <input type="text" class="form-control" id="issuer" name="issuer" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="coupon_rate" class="form-label">Coupon Rate (%) *</label>
                                <input type="number" class="form-control" id="coupon_rate" name="coupon_rate" 
                                       step="0.01" min="0" max="100" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="face_value" class="form-label">Face Value *</label>
                                <input type="number" class="form-control" id="face_value" name="face_value" 
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="issue_date" class="form-label">Issue Date *</label>
                                <input type="date" class="form-control" id="issue_date" name="issue_date" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="maturity_date" class="form-label">Maturity Date *</label>
                                <input type="date" class="form-control" id="maturity_date" name="maturity_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="currency" class="form-label">Currency</label>
                        <select class="form-select" id="currency" name="currency">
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                            <option value="TZS">TZS - Tanzanian Shilling</option>
                            <option value="KES">KES - Kenyan Shilling</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_bond" class="btn btn-primary">Add Bond</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Equity Modal -->
<div class="modal fade" id="addEquityModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Equity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Security ID Format:</strong> Use stock symbol format like WVSL, VODA 
                        (2-6 uppercase letters)
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="equity_security_id" class="form-label">Security ID (Stock Symbol) *</label>
                                <input type="text" class="form-control" id="equity_security_id" name="security_id" 
                                       placeholder="WVSL" required pattern="^[A-Z]{2,6}$" maxlength="6"
                                       style="text-transform: uppercase;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="stock_name" class="form-label">Stock Name *</label>
                                <input type="text" class="form-control" id="stock_name" name="stock_name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="company_name" class="form-label">Company Name *</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="sector" class="form-label">Sector</label>
                                <input type="text" class="form-control" id="sector" name="sector" 
                                       placeholder="e.g., Technology, Finance">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="current_price" class="form-label">Current Price *</label>
                                <input type="number" class="form-control" id="current_price" name="current_price" 
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="par_value" class="form-label">Par Value</label>
                                <input type="number" class="form-control" id="par_value" name="par_value" 
                                       step="0.01" min="0" value="1.00">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="market_cap" class="form-label">Market Cap</label>
                                <input type="number" class="form-control" id="market_cap" name="market_cap" 
                                       step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_equity" class="btn btn-primary">Add Equity</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-uppercase stock symbol input
document.getElementById('equity_security_id').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
</script>

<?php include '../includes/footer.php'; ?>
