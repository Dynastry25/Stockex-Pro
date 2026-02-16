<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow access to all authenticated users
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}

$db = getDBConnection();
$page_title = 'Document Repository';
include '../includes/header.php';

// Initialize message variables
$success_message = '';
$error_message = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_document'])) {
    $folder_id = (int)$_POST['folder_id'];
    $document_name = sanitize_input($_POST['document_name']);
    
    if (empty($document_name) || empty($folder_id)) {
        $error_message = 'All fields are required.';
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] != UPLOAD_ERR_OK) {
        $error_message = 'Please select a valid file to upload.';
    } else {
        // Get folder details
        $folder_stmt = $db->prepare("SELECT folder_name, department FROM document_folders WHERE id = ?");
        $folder_stmt->execute([$folder_id]);
        $folder = $folder_stmt->fetch();
        
        if (!$folder) {
            $error_message = 'Selected folder not found.';
        } else {
            $upload_dir = '../uploads/documents/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['document_file']['name']);
            $file_path = $upload_dir . $file_name;
            $file_type = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $file_size = $_FILES['document_file']['size'];
            
            // Validate file type
            $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'zip'];
            if (!in_array($file_type, $allowed_types)) {
                $error_message = 'Only PDF, Word, Excel, PowerPoint, images, and ZIP files are allowed.';
            } elseif ($file_size > 50 * 1024 * 1024) { // 50MB limit
                $error_message = 'File size must be less than 50MB.';
            } elseif (move_uploaded_file($_FILES['document_file']['tmp_name'], $file_path)) {
                // Insert document
                $stmt = $db->prepare("
                    INSERT INTO documents (folder_id, document_name, file_name, file_path, file_type, file_size, uploaded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([
                    $folder_id, $document_name, $file_name, $file_path, $file_type, $file_size, $_SESSION['user_id']
                ])) {
                    $success_message = 'Document uploaded successfully!';
                } else {
                    $error_message = 'Error uploading document. Please try again.';
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            } else {
                $error_message = 'Error uploading file. Please try again.';
            }
        }
    }
}

// Handle new folder creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_folder'])) {
    $folder_name = sanitize_input($_POST['new_folder_name']);
    $department = sanitize_input($_POST['new_folder_department']);
    
    if (empty($folder_name) || empty($department)) {
        $error_message = 'Folder name and department are required.';
    } else {
        // Check if folder already exists in this department
        $check_stmt = $db->prepare("SELECT id FROM document_folders WHERE folder_name = ? AND department = ?");
        $check_stmt->execute([$folder_name, $department]);
        
        if ($check_stmt->fetch()) {
            $error_message = 'A folder with this name already exists in the selected department.';
        } else {
            // Create new folder
            $folder_stmt = $db->prepare("INSERT INTO document_folders (folder_name, department, created_by) VALUES (?, ?, ?)");
            if ($folder_stmt->execute([$folder_name, $department, $_SESSION['user_id']])) {
                $success_message = 'Folder created successfully!';
            } else {
                $error_message = 'Error creating folder. Please try again.';
            }
        }
    }
}

// Get filter parameters
$current_department = $_GET['department'] ?? 'all';
$filter_document_name = $_GET['document_name'] ?? '';
$filter_folder = $_GET['folder'] ?? '';
$filter_type = $_GET['type'] ?? '';

// Build query with filters
try {
    $doc_query = "
        SELECT d.*, df.folder_name, df.department, u.full_name as uploaded_by_name
        FROM documents d
        JOIN document_folders df ON d.folder_id = df.id
        JOIN users u ON d.uploaded_by = u.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Department filter
    if ($current_department != 'all') {
        $doc_query .= " AND df.department = ?";
        $params[] = $current_department;
    }
    
    // Document Name filter
    if (!empty($filter_document_name)) {
        $doc_query .= " AND d.document_name LIKE ?";
        $params[] = "%$filter_document_name%";
    }
    
    // Folder filter
    if (!empty($filter_folder)) {
        $doc_query .= " AND df.folder_name LIKE ?";
        $params[] = "%$filter_folder%";
    }
    
    // File Type filter
    if (!empty($filter_type)) {
        $doc_query .= " AND d.file_type = ?";
        $params[] = $filter_type;
    }
    
    $doc_query .= " ORDER BY d.uploaded_at DESC";
    
    $stmt = $db->prepare($doc_query);
    $stmt->execute($params);
    $documents = $stmt->fetchAll();
} catch (Exception $e) {
    $documents = [];
}

// Get folders for dropdown
try {
    $folders = $db->query("
        SELECT df.id, df.folder_name, df.department 
        FROM document_folders df 
        ORDER BY df.department, df.folder_name
    ")->fetchAll();
} catch (Exception $e) {
    $folders = [];
}

// Get departments for filter
try {
    $departments = $db->query("SELECT DISTINCT department FROM document_folders ORDER BY department")->fetchAll();
} catch (Exception $e) {
    $departments = [];
}

// Get file types for filter
try {
    $file_types = $db->query("SELECT DISTINCT file_type FROM documents ORDER BY file_type")->fetchAll();
} catch (Exception $e) {
    $file_types = [];
}

// Get available departments for new folder form
try {
    $departments_list = $db->query("SELECT DISTINCT name as department_name FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll();
} catch (Exception $e) {
    $departments_list = [
        ['department_name' => 'HR Department'],
        ['department_name' => 'Finance Department'],
        ['department_name' => 'IT Department'],
        ['department_name' => 'Operations'],
        ['department_name' => 'Sales & Marketing'],
        ['department_name' => 'Legal Department']
    ];
}

// Check for error from download redirect
if (isset($_GET['error'])) {
    $error_message = $_GET['error'];
}
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Document Repository</h1>
        <div>
            <button class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                <i class="bi bi-folder-plus me-2"></i>Create Folder
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                <i class="bi bi-upload me-2"></i>Upload Document
            </button>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Advanced Filter Section -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Filter Documents</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <!-- Department Filter -->
                <div class="col-md-3">
                    <label class="form-label"><strong>Department:</strong></label>
                    <select class="form-select" name="department">
                        <option value="all" <?php echo $current_department == 'all' ? 'selected' : ''; ?>>All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                                <?php echo $current_department == $dept['department'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Document Name Filter -->
                <div class="col-md-3">
                    <label class="form-label"><strong>Document Name:</strong></label>
                    <input type="text" class="form-control" name="document_name" placeholder="Filter by document name..." 
                           value="<?php echo htmlspecialchars($filter_document_name); ?>">
                </div>

                <!-- Folder Filter -->
                <div class="col-md-3">
                    <label class="form-label"><strong>Folder:</strong></label>
                    <input type="text" class="form-control" name="folder" placeholder="Filter by folder..." 
                           value="<?php echo htmlspecialchars($filter_folder); ?>">
                </div>

                <!-- File Type Filter -->
                <div class="col-md-3">
                    <label class="form-label"><strong>File Type:</strong></label>
                    <select class="form-select" name="type">
                        <option value="">All Types</option>
                        <?php foreach ($file_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['file_type']); ?>" 
                                <?php echo $filter_type == $type['file_type'] ? 'selected' : ''; ?>>
                                .<?php echo htmlspecialchars($type['file_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Buttons -->
                <div class="col-12">
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-2"></i>Apply Filters
                        </button>
                        <a href="department_docs" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Documents List -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <?php echo $current_department == 'all' ? 'All Documents' : $current_department . ' Documents'; ?>
            </h6>
            <div class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Showing <?php echo count($documents); ?> document(s)
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($documents)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Document Name</th>
                                <th>Folder</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th>Uploaded By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <i class="bi bi-file-<?php echo get_file_icon($doc['file_type']); ?> text-primary me-2"></i>
                                    <strong><?php echo htmlspecialchars($doc['document_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($doc['folder_name']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($doc['department']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-info text-uppercase">.<?php echo $doc['file_type']; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($doc['uploaded_by_name']); ?></td>
                                <td><?php echo format_date($doc['uploaded_at']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($doc['file_type'] == 'pdf'): ?>
                                            <a href="view_document?id=<?php echo $doc['id']; ?>" class="btn btn-primary" target="_blank" title="View PDF">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="download_document?id=<?php echo $doc['id']; ?>" class="btn btn-success" title="Download">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-folder-x display-1 text-muted"></i>
                    <h5 class="text-muted mt-3">No documents found</h5>
                    <p class="text-muted">
                        <?php if ($current_department != 'all' || !empty($filter_document_name) || !empty($filter_folder) || !empty($filter_type)): ?>
                            No documents match your current filters.
                        <?php else: ?>
                            No documents uploaded yet.
                        <?php endif; ?>
                    </p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="bi bi-upload me-2"></i>Upload First Document
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Folder Modal -->
<div class="modal fade" id="createFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Folder Name</label>
                        <input type="text" class="form-control" name="new_folder_name" required 
                               placeholder="e.g., Reports, Contracts, Meeting Minutes">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="new_folder_department" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments_list as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_folder" class="btn btn-primary">Create Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Document Name</label>
                        <input type="text" class="form-control" name="document_name" required 
                               placeholder="e.g., Monthly Report, Meeting Minutes">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Folder</label>
                        <select class="form-select" name="folder_id" required>
                            <option value="">Select Folder</option>
                            <?php foreach ($folders as $folder): ?>
                                <option value="<?php echo $folder['id']; ?>">
                                    <?php echo htmlspecialchars($folder['folder_name']); ?> (<?php echo htmlspecialchars($folder['department']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Don't see the folder you need? <a href="#" data-bs-toggle="modal" data-bs-target="#createFolderModal">Create a new folder</a>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select File</label>
                        <input type="file" class="form-control" name="document_file" required 
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.zip">
                        <div class="form-text">
                            Allowed: PDF, Word, Excel, PowerPoint, images, ZIP (Max: 50MB)
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_document" class="btn btn-primary">Upload Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
// Helper function to get file icons
function get_file_icon($file_type) {
    $icons = [
        'pdf' => 'pdf',
        'doc' => 'word',
        'docx' => 'word',
        'xls' => 'excel',
        'xlsx' => 'excel',
        'ppt' => 'ppt',
        'pptx' => 'ppt',
        'txt' => 'text',
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'zip' => 'zip'
    ];
    return $icons[$file_type] ?? 'earmark';
}

include '../includes/footer.php'; 
?>