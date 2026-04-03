<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/forms_errors.log');

// Check if required files exist
if (!file_exists('../config/config.php')) {
    die('config.php not found');
}
if (!file_exists('../auth/auth_middleware.php')) {
    die('auth_middleware.php not found');
}

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Check if session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require login and mandate
require_login();
require_mandate();

$current_user = get_logged_in_user();

try {
    $db = getDBConnection();
    // Test the connection
    $db->query("SELECT 1");
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$error = '';

// Get company details
function getCompanyDetails($db) {
    try {
        $stmt = $db->prepare("SELECT company_code, name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['company_code' => 'B13/C', 'company_name' => 'Victory Financial Services'];
        }
        
        return [
            'company_code' => $result['company_code'],
            'company_name' => $result['company_name']
        ];
    } catch (Exception $e) {
        return ['company_code' => 'B000/C', 'company_name' => 'Neovam Technologies LTD'];
    }
}

$company_details = getCompanyDetails($db);
$company_code = $company_details['company_code'];
$company_name = $company_details['company_name'];

// Create forms table if it doesn't exist
function createFormsTable($db) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS `forms` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `form_name` varchar(255) NOT NULL,
            `form_description` text,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `file_type` varchar(50) NOT NULL,
            `file_size` int(11) NOT NULL,
            `uploaded_by` int(11) DEFAULT NULL,
            `uploaded_by_username` varchar(100) DEFAULT NULL,
            `download_count` int(11) DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_form_name` (`form_name`),
            KEY `idx_file_type` (`file_type`),
            KEY `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $db->exec($sql);
        
        // Check if we need to add any columns
        $checkStmt = $db->query("SHOW COLUMNS FROM forms LIKE 'download_count'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE forms ADD COLUMN download_count INT DEFAULT 0 AFTER uploaded_by_username");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error creating forms table: " . $e->getMessage());
        return false;
    }
}

// Create uploads directory if it doesn't exist
$upload_dir = '../uploads/forms/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Create forms table
createFormsTable($db);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'upload_form') {
        try {
            $form_name = trim($_POST['form_name'] ?? '');
            $form_description = trim($_POST['form_description'] ?? '');
            
            if (empty($form_name)) {
                throw new Exception('Form name is required');
            }
            
            if (!isset($_FILES['form_file']) || $_FILES['form_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please select a file to upload');
            }
            
            $file = $_FILES['form_file'];
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_size = $file['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Validate file type - Added Word documents
            $allowed_types = ['pdf', 'xls', 'xlsx', 'csv', 'doc', 'docx'];
            if (!in_array($file_ext, $allowed_types)) {
                throw new Exception('Only PDF, Excel, and Word documents are allowed');
            }
            
            // Validate file size (max 10MB)
            $max_size = 10 * 1024 * 1024; // 10MB
            if ($file_size > $max_size) {
                throw new Exception('File size must be less than 10MB');
            }
            
            // Generate unique filename
            $new_file_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file_name);
            $file_path = $upload_dir . $new_file_name;
            
            // Determine file type category
            $file_type = 'other';
            if ($file_ext === 'pdf') {
                $file_type = 'pdf';
            } elseif (in_array($file_ext, ['xls', 'xlsx', 'csv'])) {
                $file_type = 'excel';
            } elseif (in_array($file_ext, ['doc', 'docx'])) {
                $file_type = 'word';
            }
            
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $file_path)) {
                // Insert into database
                $stmt = $db->prepare("
                    INSERT INTO forms (form_name, form_description, file_name, file_path, file_type, file_size, uploaded_by, uploaded_by_username)
                    VALUES (:form_name, :form_description, :file_name, :file_path, :file_type, :file_size, :uploaded_by, :uploaded_by_username)
                ");
                
                $stmt->execute([
                    ':form_name' => $form_name,
                    ':form_description' => $form_description,
                    ':file_name' => $file_name,
                    ':file_path' => $file_path,
                    ':file_type' => $file_type,
                    ':file_size' => $file_size,
                    ':uploaded_by' => $current_user['id'] ?? null,
                    ':uploaded_by_username' => $current_user['username'] ?? 'Unknown'
                ]);
                
                $message = "Form uploaded successfully!";
            } else {
                throw new Exception('Failed to upload file');
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete_form') {
        // Delete form
        try {
            $form_id = $_POST['form_id'] ?? 0;
            
            // Get file path before deleting
            $stmt = $db->prepare("SELECT file_path FROM forms WHERE id = :id");
            $stmt->execute([':id' => $form_id]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($form) {
                // Delete file from server
                if (file_exists($form['file_path'])) {
                    unlink($form['file_path']);
                }
                
                // Delete from database
                $stmt = $db->prepare("DELETE FROM forms WHERE id = :id");
                $stmt->execute([':id' => $form_id]);
                
                $message = "Form deleted successfully!";
            } else {
                throw new Exception('Form not found');
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'toggle_form_status') {
        // Toggle active/inactive status
        try {
            $form_id = $_POST['form_id'] ?? 0;
            $current_status = $_POST['current_status'] ?? 1;
            $new_status = $current_status ? 0 : 1;
            
            $stmt = $db->prepare("UPDATE forms SET is_active = :is_active WHERE id = :id");
            $stmt->execute([
                ':is_active' => $new_status,
                ':id' => $form_id
            ]);
            
            $message = "Form status updated successfully!";
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'update_form') {
        // Update form details
        try {
            $form_id = $_POST['form_id'] ?? 0;
            $form_name = trim($_POST['form_name'] ?? '');
            $form_description = trim($_POST['form_description'] ?? '');
            
            if (empty($form_name)) {
                throw new Exception('Form name is required');
            }
            
            $stmt = $db->prepare("
                UPDATE forms 
                SET form_name = :form_name, form_description = :form_description
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':form_name' => $form_name,
                ':form_description' => $form_description,
                ':id' => $form_id
            ]);
            
            $message = "Form updated successfully!";
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle file download
if (isset($_GET['download'])) {
    $form_id = $_GET['download'];
    
    try {
        $stmt = $db->prepare("SELECT * FROM forms WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $form_id]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($form && file_exists($form['file_path'])) {
            // Increment download count
            $updateStmt = $db->prepare("UPDATE forms SET download_count = download_count + 1 WHERE id = :id");
            $updateStmt->execute([':id' => $form_id]);
            
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $form['file_name'] . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($form['file_path']));
            
            // Clear output buffer
            ob_clean();
            flush();
            
            // Read file
            readfile($form['file_path']);
            exit;
        } else {
            $error = "File not found or inaccessible";
        }
    } catch (Exception $e) {
        $error = "Error downloading file: " . $e->getMessage();
    }
}

// Get all forms
function getForms($db, $include_inactive = false) {
    try {
        $query = "SELECT * FROM forms ORDER BY created_at DESC";
        $stmt = $db->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting forms: " . $e->getMessage());
        return [];
    }
}

$forms = getForms($db);

// Get form statistics
$total_forms = count($forms);
$pdf_count = 0;
$excel_count = 0;
$word_count = 0;
$total_downloads = 0;

foreach ($forms as $form) {
    if ($form['file_type'] === 'pdf') {
        $pdf_count++;
    } elseif ($form['file_type'] === 'excel') {
        $excel_count++;
    } elseif ($form['file_type'] === 'word') {
        $word_count++;
    }
    $total_downloads += $form['download_count'];
}

include '../includes/header.php';
?>

<div class="container-fluid">

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card primary-stat">
                <h6 class="text-muted mb-2">Total Forms</h6>
                <h3 class="mb-0"><?php echo $total_forms; ?></h3>
                <small class="text-primary">
                    <i class="bi bi-file-earmark me-1"></i>All uploaded forms
                </small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card danger-stat">
                <h6 class="text-muted mb-2">PDF Forms</h6>
                <h3 class="mb-0"><?php echo $pdf_count; ?></h3>
                <small class="text-danger">
                    <i class="bi bi-file-pdf me-1"></i>PDF documents
                </small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card success-stat">
                <h6 class="text-muted mb-2">Excel Forms</h6>
                <h3 class="mb-0"><?php echo $excel_count; ?></h3>
                <small class="text-success">
                    <i class="bi bi-file-excel me-1"></i>Excel spreadsheets
                </small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card info-stat">
                <h6 class="text-muted mb-2">Word Documents</h6>
                <h3 class="mb-0"><?php echo $word_count; ?></h3>
                <small class="text-info">
                    <i class="bi bi-file-word me-1"></i>Word documents
                </small>
            </div>
        </div>
    </div>

    <!-- Second Row Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card warning-stat">
                <h6 class="text-muted mb-2">Total Downloads</h6>
                <h3 class="mb-0"><?php echo number_format($total_downloads); ?></h3>
                <small class="text-warning">
                    <i class="bi bi-download me-1"></i>All time downloads
                </small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card secondary-stat">
                <h6 class="text-muted mb-2">Active Forms</h6>
                <h3 class="mb-0"><?php echo count(array_filter($forms, function($f) { return $f['is_active']; })); ?></h3>
                <small class="text-secondary">
                    <i class="bi bi-check-circle me-1"></i>Currently active
                </small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card dark-stat">
                <h6 class="text-muted mb-2">Storage Used</h6>
                <?php
                $total_size = array_sum(array_column($forms, 'file_size'));
                if ($total_size < 1024) {
                    $storage = $total_size . ' B';
                } elseif ($total_size < 1048576) {
                    $storage = round($total_size / 1024, 2) . ' KB';
                } else {
                    $storage = round($total_size / 1048576, 2) . ' MB';
                }
                ?>
                <h3 class="mb-0"><?php echo $storage; ?></h3>
                <small class="text-dark">
                    <i class="bi bi-hdd-stack me-1"></i>Total storage
                </small>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row">
        <!-- Upload Form Section (Left Column) -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-cloud-upload me-2"></i>Upload New Form</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="action" value="upload_form">
                        
                        <div class="mb-3">
                            <label for="form_name" class="form-label">Form Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="form_name" name="form_name" required 
                                   placeholder="e.g., Client Application Form">
                        </div>
                        
                        <div class="mb-3">
                            <label for="form_description" class="form-label">Description</label>
                            <textarea class="form-control" id="form_description" name="form_description" rows="3" 
                                      placeholder="Brief description of the form and its purpose"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="form_file" class="form-label">Select File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="form_file" name="form_file" required 
                                   accept=".pdf,.xls,.xlsx,.csv,.doc,.docx">
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                Allowed: PDF, Excel (xls, xlsx, csv), Word (doc, docx) | Max size: 10MB
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="progress" style="height: 20px; display: none;" id="uploadProgress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 0%;" id="progressBar">0%</div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100" id="uploadBtn">
                            <i class="bi bi-cloud-upload me-2"></i>Upload Form
                        </button>
                    </form>
                    
                    <hr class="my-4">
                    
                    <div class="alert alert-info mb-0">
                        <h6 class="alert-heading"><i class="bi bi-info-circle me-2"></i>Upload Guidelines:</h6>
                        <ul class="mb-0 small">
                            <li>Use clear, descriptive names for forms</li>
                            <li>Add detailed descriptions to help users understand the form's purpose</li>
                            <li>Ensure PDF files are not password-protected</li>
                            <li>Excel files should be in a readable format</li>
                            <li>Word documents should be in .doc or .docx format</li>
                            <li>Maximum file size: 10MB</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Forms List Section (Right Column) -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-files me-2"></i>Available Forms</h5>
                    <div>
                        <span class="badge bg-light text-dark me-2">Total: <?php echo $total_forms; ?></span>
                        <span class="badge bg-danger me-2">PDF: <?php echo $pdf_count; ?></span>
                        <span class="badge bg-success me-2">Excel: <?php echo $excel_count; ?></span>
                        <span class="badge bg-info">Word: <?php echo $word_count; ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($forms)): ?>
                        <div class="text-center py-5">
                            <div class="display-1 text-muted mb-4">
                                <i class="bi bi-file-earmark"></i>
                            </div>
                            <h5>No Forms Uploaded Yet</h5>
                            <p class="text-muted">Upload your first form using the form on the left.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="formsTable">
                                <thead>
                                    <tr>
                                        <th>Form Name</th>
                                        <th>Description</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Downloads</th>
                                        <th>Uploaded</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form): 
                                        // Fix for strtotime error - check if created_at exists and is valid
                                        $created_date = '';
                                        if (!empty($form['created_at']) && $form['created_at'] !== '0000-00-00 00:00:00') {
                                            try {
                                                $timestamp = strtotime($form['created_at']);
                                                if ($timestamp !== false) {
                                                    $created_date = date('d M Y', $timestamp);
                                                } else {
                                                    $created_date = 'Date unknown';
                                                }
                                            } catch (Exception $e) {
                                                $created_date = 'Date unknown';
                                            }
                                        } else {
                                            $created_date = 'Date unknown';
                                        }
                                        
                                        $file_size_formatted = '';
                                        if ($form['file_size'] < 1024) {
                                            $file_size_formatted = $form['file_size'] . ' B';
                                        } elseif ($form['file_size'] < 1048576) {
                                            $file_size_formatted = round($form['file_size'] / 1024, 2) . ' KB';
                                        } else {
                                            $file_size_formatted = round($form['file_size'] / 1048576, 2) . ' MB';
                                        }
                                        
                                        // Set icon and badge based on file type
                                        if ($form['file_type'] === 'pdf') {
                                            $type_icon = 'bi-file-pdf text-danger';
                                            $type_badge = 'bg-danger';
                                            $type_label = 'PDF';
                                        } elseif ($form['file_type'] === 'excel') {
                                            $type_icon = 'bi-file-excel text-success';
                                            $type_badge = 'bg-success';
                                            $type_label = 'EXCEL';
                                        } elseif ($form['file_type'] === 'word') {
                                            $type_icon = 'bi-file-word text-primary';
                                            $type_badge = 'bg-primary';
                                            $type_label = 'WORD';
                                        } else {
                                            $type_icon = 'bi-file-earmark text-secondary';
                                            $type_badge = 'bg-secondary';
                                            $type_label = 'OTHER';
                                        }
                                    ?>
                                    <tr class="<?php echo $form['is_active'] ? '' : 'table-secondary'; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($form['form_name']); ?></strong>
                                            <?php if (!$form['is_active']): ?>
                                                <span class="badge bg-secondary ms-2">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($form['form_description'] ?: 'No description'); ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $type_badge; ?>">
                                                <i class="bi <?php echo $type_icon; ?> me-1"></i>
                                                <?php echo $type_label; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $file_size_formatted; ?></td>
                                        <td>
                                            <span class="badge bg-info">
                                                <i class="bi bi-download me-1"></i>
                                                <?php echo number_format($form['download_count']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <i class="bi bi-person me-1"></i>
                                                <?php echo htmlspecialchars($form['uploaded_by_username'] ?: 'Unknown'); ?><br>
                                                <i class="bi bi-clock me-1"></i>
                                                <?php echo $created_date; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?download=<?php echo $form['id']; ?>" class="btn btn-success" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <button type="button" class="btn btn-primary" title="Edit" 
                                                        onclick="editForm(<?php echo htmlspecialchars(json_encode($form)); ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($form['is_active']): ?>
                                                    <button type="button" class="btn btn-warning" title="Deactivate"
                                                            onclick="toggleFormStatus(<?php echo $form['id']; ?>, 1)">
                                                        <i class="bi bi-eye-slash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-info" title="Activate"
                                                            onclick="toggleFormStatus(<?php echo $form['id']; ?>, 0)">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-danger" title="Delete"
                                                        onclick="deleteForm(<?php echo $form['id']; ?>, '<?php echo htmlspecialchars($form['form_name']); ?>')">
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
        </div>
    </div>
    
    <!-- Quick Access Section -->
 
</div>

<!-- Edit Form Modal -->
<div class="modal fade" id="editFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Form</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_form">
                    <input type="hidden" name="form_id" id="edit_form_id">
                    
                    <div class="mb-3">
                        <label for="edit_form_name" class="form-label">Form Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_form_name" name="form_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_form_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_form_description" name="form_description" rows="3"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        To replace the file, please delete this form and upload a new one.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Form</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form Modal -->
<div class="modal fade" id="deleteFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_form">
                    <input type="hidden" name="form_id" id="delete_form_id">
                    
                    <p>Are you sure you want to delete the form: <strong id="delete_form_name"></strong>?</p>
                    <p class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Form</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Form -->
<form method="POST" id="toggleForm" style="display: none;">
    <input type="hidden" name="action" value="toggle_form_status">
    <input type="hidden" name="form_id" id="toggle_form_id">
    <input type="hidden" name="current_status" id="toggle_current_status">
</form>

<style>
body {
    background-color: #f8f9fa;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.stats-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    border-left: 4px solid;
}
.primary-stat { border-left-color: #0d6efd; }
.danger-stat { border-left-color: #dc3545; }
.success-stat { border-left-color: #28a745; }
.info-stat { border-left-color: #17a2b8; }
.warning-stat { border-left-color: #ffc107; }
.secondary-stat { border-left-color: #6c757d; }
.dark-stat { border-left-color: #343a40; }
.card {
    border-radius: 10px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    border: none;
}
.card-header {
    border-radius: 10px 10px 0 0 !important;
    font-weight: 600;
}
.table th {
    border-top: none;
    font-weight: 600;
    color: #495057;
}
.table td {
    vertical-align: middle;
}
.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
}
.progress {
    border-radius: 20px;
    background-color: #e9ecef;
}
.progress-bar {
    border-radius: 20px;
    background-color: #0d6efd;
}
.alert-info {
    background-color: rgba(13, 202, 240, 0.1);
    border-color: rgba(13, 202, 240, 0.2);
    color: #055160;
}
.badge {
    padding: 0.5em 0.8em;
    font-weight: 500;
}
.d-flex.align-items-center {
    transition: all 0.3s ease;
}
.d-flex.align-items-center:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}
/* Responsive adjustments */
@media (max-width: 768px) {
    .stats-card {
        margin-bottom: 10px;
    }
    .btn-group-sm > .btn {
        padding: 0.2rem 0.3rem;
    }
}
</style>

<script>
// Upload progress simulation
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('form_file');
    if (fileInput.files.length > 0) {
        const fileSize = fileInput.files[0].size;
        const maxSize = 10 * 1024 * 1024; // 10MB
        
        if (fileSize > maxSize) {
            e.preventDefault();
            alert('File size must be less than 10MB');
            return false;
        }
        
        // Show progress bar
        document.getElementById('uploadProgress').style.display = 'block';
        document.getElementById('uploadBtn').disabled = true;
        document.getElementById('uploadBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
        
        // Simulate progress (for demo - real progress would need AJAX)
        let progress = 0;
        const interval = setInterval(function() {
            progress += 10;
            document.getElementById('progressBar').style.width = progress + '%';
            document.getElementById('progressBar').innerHTML = progress + '%';
            
            if (progress >= 100) {
                clearInterval(interval);
            }
        }, 200);
    }
});

// Edit form function
function editForm(formData) {
    document.getElementById('edit_form_id').value = formData.id;
    document.getElementById('edit_form_name').value = formData.form_name;
    document.getElementById('edit_form_description').value = formData.form_description || '';
    
    new bootstrap.Modal(document.getElementById('editFormModal')).show();
}

// Delete form function
function deleteForm(id, name) {
    document.getElementById('delete_form_id').value = id;
    document.getElementById('delete_form_name').textContent = name;
    
    new bootstrap.Modal(document.getElementById('deleteFormModal')).show();
}

// Toggle form status
function toggleFormStatus(id, currentStatus) {
    if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this form?')) {
        document.getElementById('toggle_form_id').value = id;
        document.getElementById('toggle_current_status').value = currentStatus;
        document.getElementById('toggleForm').submit();
    }
}

// Filter by type
function filterByType(type) {
    const table = document.getElementById('formsTable');
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const typeCell = rows[i].getElementsByTagName('td')[2];
        if (typeCell) {
            const typeText = typeCell.textContent || typeCell.innerText;
            if (type.toLowerCase() === 'pdf' && typeText.includes('PDF')) {
                rows[i].style.display = '';
            } else if (type.toLowerCase() === 'excel' && typeText.includes('EXCEL')) {
                rows[i].style.display = '';
            } else if (type.toLowerCase() === 'word' && typeText.includes('WORD')) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    }
}

// Reset filter
function resetFilter() {
    const table = document.getElementById('formsTable');
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        rows[i].style.display = '';
    }
}

// Show top downloads
function showTopDownloads() {
    const table = document.getElementById('formsTable');
    const rows = Array.from(table.getElementsByTagName('tr')).slice(1);
    
    // Sort rows by download count
    rows.sort((a, b) => {
        const aDownloads = parseInt(a.getElementsByTagName('td')[4].textContent.replace(/[^0-9]/g, ''));
        const bDownloads = parseInt(b.getElementsByTagName('td')[4].textContent.replace(/[^0-9]/g, ''));
        return bDownloads - aDownloads;
    });
    
    // Reorder table
    const tbody = table.getElementsByTagName('tbody')[0];
    rows.forEach(row => tbody.appendChild(row));
    
    // Highlight top 3
    for (let i = 0; i < Math.min(3, rows.length); i++) {
        rows[i].classList.add('table-warning');
    }
    
    alert('Forms sorted by download count. Top 3 highlighted in yellow.');
}

// Search/filter function
document.addEventListener('keyup', function(e) {
    if (e.target.id === 'searchForms') {
        const searchText = e.target.value.toLowerCase();
        const table = document.getElementById('formsTable');
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const nameCell = rows[i].getElementsByTagName('td')[0];
            const descCell = rows[i].getElementsByTagName('td')[1];
            
            if (nameCell && descCell) {
                const nameText = nameCell.textContent || nameCell.innerText;
                const descText = descCell.textContent || descCell.innerText;
                
                if (nameText.toLowerCase().indexOf(searchText) > -1 || 
                    descText.toLowerCase().indexOf(searchText) > -1) {
                    rows[i].style.display = '';
                } else {
                    rows[i].style.display = 'none';
                }
            }
        }
    }
});

// Add search box to table header
document.addEventListener('DOMContentLoaded', function() {
    const tableHeader = document.querySelector('#formsTable thead tr');
    if (tableHeader) {
        const searchCell = document.createElement('th');
        searchCell.colSpan = 7;
        searchCell.innerHTML = '<div class="input-group input-group-sm"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="searchForms" placeholder="Search forms by name or description..."></div>';
        const newRow = document.createElement('tr');
        newRow.appendChild(searchCell);
        tableHeader.parentNode.insertBefore(newRow, tableHeader.nextSibling);
    }
    
    // Add reset filter button
    const actionButtons = document.querySelector('.d-flex.flex-wrap.gap-2.mb-4');
    if (actionButtons) {
        const resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'btn btn-outline-secondary';
        resetBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-2"></i>Reset Filters';
        resetBtn.onclick = resetFilter;
        actionButtons.appendChild(resetBtn);
    }
});
</script>

<?php include '../includes/footer.php'; ?>