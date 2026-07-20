<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Create uploads directories if they don't exist
$upload_dirs = [
    '../uploads/companies/logos/',
    '../uploads/companies/headers/',
    '../uploads/companies/footers/'
];

foreach ($upload_dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Handle file upload
function handleImageUpload($file, $type, $company_id) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, and GIF files are allowed.'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 5MB.'];
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $company_id . '_' . $type . '_' . time() . '.' . $extension;
    $upload_path = "../uploads/companies/{$type}s/" . $filename;
    $relative_path = "uploads/companies/{$type}s/" . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return ['success' => true, 'path' => $relative_path];
    } else {
        return ['success' => false, 'message' => 'Failed to upload file.'];
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_company'])) {
        // Add new company
        $company_code = sanitize_input($_POST['company_code']);
        $company_name = sanitize_input($_POST['company_name']);
        $registration_number = sanitize_input($_POST['registration_number']);
        $address = sanitize_input($_POST['address']);
        $contact_person = sanitize_input($_POST['contact_person']);
        $phone = sanitize_input($_POST['phone']);
        $email = sanitize_input($_POST['email']);
        
        if (empty($company_code) || empty($company_name)) {
            $error_message = 'Company code and name are required.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO companies (company_code, company_name, registration_number, address, 
                                         contact_person, phone, email) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$company_code, $company_name, $registration_number, $address, 
                                  $contact_person, $phone, $email])) {
                    $company_id = $db->lastInsertId();
                    show_alert('Company added successfully.', 'success');
                    redirect('admin/companies.php');
                } else {
                    $error_message = 'Error adding company.';
                }
            } catch (Exception $e) {
                $error_message = 'Error: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_company'])) {
        // Update existing company
        $company_id = (int)$_POST['company_id'];
        $company_code = sanitize_input($_POST['company_code']);
        $company_name = sanitize_input($_POST['company_name']);
        $registration_number = sanitize_input($_POST['registration_number']);
        $address = sanitize_input($_POST['address']);
        $contact_person = sanitize_input($_POST['contact_person']);
        $phone = sanitize_input($_POST['phone']);
        $email = sanitize_input($_POST['email']);
        
        try {
            $stmt = $db->prepare("
                UPDATE companies 
                SET company_code = ?, company_name = ?, registration_number = ?, address = ?, 
                    contact_person = ?, phone = ?, email = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([$company_code, $company_name, $registration_number, $address, 
                              $contact_person, $phone, $email, $company_id])) {
                show_alert('Company updated successfully.', 'success');
                redirect('admin/companies.php');
            } else {
                $error_message = 'Error updating company.';
            }
        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['upload_images'])) {
        // Handle image uploads
        $company_id = (int)$_POST['company_id'];
        $updates = [];
        $params = [];
        
        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $result = handleImageUpload($_FILES['logo'], 'logo', $company_id);
            if ($result['success']) {
                $updates[] = "logo_path = ?";
                $params[] = $result['path'];
            } else {
                $error_message = 'Logo upload error: ' . $result['message'];
            }
        }
        
        // Handle header upload
        if (isset($_FILES['header']) && $_FILES['header']['error'] == 0) {
            $result = handleImageUpload($_FILES['header'], 'header', $company_id);
            if ($result['success']) {
                $updates[] = "header_image_path = ?";
                $params[] = $result['path'];
            } else {
                $error_message = 'Header upload error: ' . $result['message'];
            }
        }
        
        // Handle footer upload
        if (isset($_FILES['footer']) && $_FILES['footer']['error'] == 0) {
            $result = handleImageUpload($_FILES['footer'], 'footer', $company_id);
            if ($result['success']) {
                $updates[] = "footer_image_path = ?";
                $params[] = $result['path'];
            } else {
                $error_message = 'Footer upload error: ' . $result['message'];
            }
        }
        
        if (!empty($updates) && empty($error_message)) {
            $params[] = $company_id;
            $sql = "UPDATE companies SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($params)) {
                show_alert('Images uploaded successfully.', 'success');
                redirect('admin/companies.php');
            } else {
                $error_message = 'Error updating company images.';
            }
        }
    }
}

// Handle company status toggle
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $company_id = (int)$_GET['id'];
    $stmt = $db->prepare("UPDATE companies SET is_active = NOT is_active WHERE id = ?");
    if ($stmt->execute([$company_id])) {
        show_alert('Company status updated.', 'success');
    } else {
        show_alert('Error updating company status.', 'danger');
    }
    redirect('admin/companies.php');
}

// Get companies with search and filter
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize_input($_GET['status']) : '';

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(company_name LIKE ? OR company_code LIKE ? OR contact_person LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status !== '') {
    $where_conditions[] = "is_active = ?";
    $params[] = ($status == '1') ? 1 : 0;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$stmt = $db->prepare("SELECT * FROM companies $where_clause ORDER BY company_name");
$stmt->execute($params);
$companies = $stmt->fetchAll();

$page_title = 'Company Management';
include '../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-building"></i> Company Management</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCompanyModal">
                <i class="bi bi-plus-lg"></i> Add Company
            </button>
        </div>
    </div>
</div>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search companies...">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All Companies</option>
                    <option value="1" <?php echo ($status === '1') ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo ($status === '0') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid">
                    <button type="submit" class="btn btn-outline-primary">Search</button>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid">
                    <a href="companies.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Companies Table -->
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Companies (<?php echo count($companies); ?>)</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Company Name</th>
                        <th>Contact</th>
                        <th>Images</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($companies as $company): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($company['company_code']); ?></strong></td>
                            <td>
                                <div>
                      <small class="text-muted"><?php echo htmlspecialchars($company['registration_number']); ?></small>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <?php echo htmlspecialchars($company['contact_person']); ?><br>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($company['phone']); ?><br>
                                        <?php echo htmlspecialchars($company['email']); ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if ($company['logo_path']): ?>
                                        <span class="badge bg-success" title="Logo uploaded">L</span>
                                    <?php endif; ?>
                                    <?php if ($company['header_image_path']): ?>
                                        <span class="badge bg-info" title="Header uploaded">H</span>
                                    <?php endif; ?>
                                    <?php if ($company['footer_image_path']): ?>
                                        <span class="badge bg-warning" title="Footer uploaded">F</span>
                                    <?php endif; ?>
                                    <?php if (!$company['logo_path'] && !$company['header_image_path'] && !$company['footer_image_path']): ?>
                                        <span class="text-muted">No images</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $company['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $company['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" 
                                            onclick="editCompany(<?php echo htmlspecialchars(json_encode($company)); ?>)"
                                            title="Edit Company">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-info" 
                                            onclick="manageImages(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars($company['company_name']); ?>')"
                                            title="Manage Images">
                                        <i class="bi bi-image"></i>
                                    </button>
                                    <a href="?toggle=1&id=<?php echo $company['id']; ?>" 
                                       class="btn btn-outline-<?php echo $company['is_active'] ? 'warning' : 'success'; ?>"
                                       onclick="return confirm('<?php echo $company['is_active'] ? 'Deactivate' : 'Activate'; ?> this company?')"
                                       title="<?php echo $company['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                        <i class="bi bi-<?php echo $company['is_active'] ? 'pause' : 'play'; ?>"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Company Modal -->
<div class="modal fade" id="addCompanyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="company_code" class="form-label">Company Code *</label>
                                <input type="text" class="form-control" id="company_code" name="company_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="company_name" class="form-label">Company Name *</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="registration_number" class="form-label">Registration Number</label>
                                <input type="text" class="form-control" id="registration_number" name="registration_number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"></textarea>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_company" class="btn btn-primary">Add Company</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Company Modal -->
<div class="modal fade" id="editCompanyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" id="edit_company_id" name="company_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Same form fields as add modal but with edit_ prefixes -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_company_code" class="form-label">Company Code *</label>
                                <input type="text" class="form-control" id="edit_company_code" name="company_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_company_name" class="form-label">Company Name *</label>
                                <input type="text" class="form-control" id="edit_company_name" name="company_name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_registration_number" class="form-label">Registration Number</label>
                                <input type="text" class="form-control" id="edit_registration_number" name="registration_number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="edit_contact_person" name="contact_person">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="edit_phone" name="phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_company" class="btn btn-primary">Update Company</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Image Management Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" id="image_company_id" name="company_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalTitle">Manage Company Images</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="logo" class="form-label">Company Logo</label>
                                <input type="file" class="form-control" id="logo" name="logo" accept="image/*">
                                <div class="form-text">Recommended: 200x100px, Max: 5MB</div>
                                <div id="current_logo" class="mt-2"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="header" class="form-label">Header Image</label>
                                <input type="file" class="form-control" id="header" name="header" accept="image/*">
                                <div class="form-text">For receipts/invoices header</div>
                                <div id="current_header" class="mt-2"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="footer" class="form-label">Footer Image</label>
                                <input type="file" class="form-control" id="footer" name="footer" accept="image/*">
                                <div class="form-text">For receipts/invoices footer</div>
                                <div id="current_footer" class="mt-2"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Image Guidelines:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Logo: Used in system branding and documents</li>
                            <li>Header: Appears at the top of receipts and invoices</li>
                            <li>Footer: Appears at the bottom of receipts and invoices</li>
                            <li>Supported formats: JPG, PNG, GIF</li>
                            <li>Maximum file size: 5MB per image</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_images" class="btn btn-primary">Upload Images</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCompany(company) {
    document.getElementById('edit_company_id').value = company.id;
    document.getElementById('edit_company_code').value = company.company_code;
    document.getElementById('edit_company_name').value = company.company_name;
    document.getElementById('edit_registration_number').value = company.registration_number || '';
    document.getElementById('edit_contact_person').value = company.contact_person || '';
    document.getElementById('edit_address').value = company.address || '';
    document.getElementById('edit_phone').value = company.phone || '';
    document.getElementById('edit_email').value = company.email || '';
    
    new bootstrap.Modal(document.getElementById('editCompanyModal')).show();
}

function manageImages(companyId, companyName) {
    document.getElementById('image_company_id').value = companyId;
    document.getElementById('imageModalTitle').textContent = 'Manage Images - ' + companyName;
    
    // Clear previous image previews
    document.getElementById('current_logo').innerHTML = '';
    document.getElementById('current_header').innerHTML = '';
    document.getElementById('current_footer').innerHTML = '';
    
    // You could fetch and display current images here via AJAX if needed
    
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
