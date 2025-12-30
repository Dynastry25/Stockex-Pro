<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $settings = $_POST['settings'];
    
    try {
        $db->beginTransaction();
        
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
            $stmt->execute([sanitize_input($value), $_SESSION['user_id'], $key]);
        }
        
        $db->commit();
        show_alert('Settings updated successfully.', 'success');
        redirect('admin/settings.php');
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = 'Error updating settings: ' . $e->getMessage();
    }
}

// Get current settings
$stmt = $db->query("SELECT * FROM system_settings ORDER BY setting_key");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row;
}

$page_title = 'System Settings';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-gear"></i> System Settings</h2>
        </div>
    </div>
</div>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">General Settings</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="system_name" class="form-label">System Name</label>
                        <input type="text" class="form-control" id="system_name" name="settings[system_name]" 
                               value="<?php echo htmlspecialchars($settings['system_name']['setting_value'] ?? ''); ?>" required>
                        <div class="form-text"><?php echo htmlspecialchars($settings['system_name']['description'] ?? ''); ?></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="company_name" class="form-label">Company Name</label>
                        <input type="text" class="form-control" id="company_name" name="settings[company_name]" 
                               value="<?php echo htmlspecialchars($settings['company_name']['setting_value'] ?? ''); ?>" required>
                        <div class="form-text"><?php echo htmlspecialchars($settings['company_name']['description'] ?? ''); ?></div>
                    </div>
                    
                    <!-- Added link to company management -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Company Branding:</strong> 
                        To upload company logos, headers, and footers for receipts and invoices, 
                        <a href="companies.php" class="alert-link">manage companies here</a>.
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="receipt_prefix" class="form-label">Receipt Prefix</label>
                                <input type="text" class="form-control" id="receipt_prefix" name="settings[receipt_prefix]" 
                                       value="<?php echo htmlspecialchars($settings['receipt_prefix']['setting_value'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="invoice_prefix" class="form-label">Invoice Prefix</label>
                                <input type="text" class="form-control" id="invoice_prefix" name="settings[invoice_prefix]" 
                                       value="<?php echo htmlspecialchars($settings['invoice_prefix']['setting_value'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="trade_prefix" class="form-label">Trade Prefix</label>
                                <input type="text" class="form-control" id="trade_prefix" name="settings[trade_prefix]" 
                                       value="<?php echo htmlspecialchars($settings['trade_prefix']['setting_value'] ?? ''); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Update Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">System Information</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <strong>PHP Version:</strong><br>
                    <span class="text-muted"><?php echo PHP_VERSION; ?></span>
                </div>
                
                <div class="mb-3">
                    <strong>Database:</strong><br>
                    <span class="text-muted">MySQL</span>
                </div>
                
                <div class="mb-3">
                    <strong>Server Time:</strong><br>
                    <span class="text-muted"><?php echo date('Y-m-d H:i:s'); ?></span>
                </div>
                
                <div class="mb-3">
                    <strong>Last Settings Update:</strong><br>
                    <span class="text-muted">
                        <?php 
                        $last_update = '';
                        foreach ($settings as $setting) {
                            if ($setting['updated_at'] > $last_update) {
                                $last_update = $setting['updated_at'];
                            }
                        }
                        echo $last_update ? format_date($last_update) : 'Never';
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="dashboard.php" class="btn btn-outline-primary">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="users.php" class="btn btn-outline-secondary">
                        <i class="bi bi-people"></i> Manage Users
                    </a>
                    <!-- Added company management link -->
                    <a href="companies.php" class="btn btn-outline-success">
                        <i class="bi bi-building"></i> Manage Companies
                    </a>
                    <a href="../reports/" class="btn btn-outline-info">
                        <i class="bi bi-file-earmark-text"></i> View Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
