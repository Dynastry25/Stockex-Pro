<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

if (!isset($_SESSION['user_id'])) {
    redirect('auth/login.php');
}

$db = getDBConnection();

// Get folder ID from URL
$folder_id = (int)$_GET['id'];
if (!$folder_id) {
    redirect('department_docs.php');
}

// Get folder details
$stmt = $db->prepare("
    SELECT df.*, u.full_name as created_by_name
    FROM document_folders df
    LEFT JOIN users u ON df.created_by = u.id
    WHERE df.id = ?
");
$stmt->execute([$folder_id]);
$folder = $stmt->fetch();

if (!$folder) {
    $_SESSION['error'] = 'Folder not found.';
    redirect('department_docs.php');
}

$page_title = $folder['folder_name'] . ' - Documents';
include '../includes/header.php';

// Get documents in this folder
$stmt = $db->prepare("
    SELECT d.*, u.full_name as uploaded_by_name
    FROM documents d
    LEFT JOIN users u ON d.uploaded_by = u.id
    WHERE d.folder_id = ?
    ORDER BY d.uploaded_at DESC
");
$stmt->execute([$folder_id]);
$documents = $stmt->fetchAll();
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="bi bi-folder-fill text-warning me-2"></i>
                <?php echo htmlspecialchars($folder['folder_name']); ?>
            </h1>
            <p class="text-muted mb-0">
                <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($folder['department']); ?>
                <?php if ($folder['description']): ?>
                • <?php echo htmlspecialchars($folder['description']); ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex">
            <a href="department_docs" class="btn btn-secondary me-2">
                <i class="bi bi-arrow-left me-2"></i>Back to Repository
            </a>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                <i class="bi bi-upload me-2"></i>Upload to this Folder
            </button>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Documents in this Folder</h6>
                    <span class="badge bg-primary"><?php echo count($documents); ?> documents</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($documents)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Document Name</th>
                                    <th>File Type</th>
                                    <th>Size</th>
                                    <th>Uploaded By</th>
                                    <th>Upload Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td>
                                        <i class="bi bi-file-<?php echo get_file_icon($doc['file_type']); ?> text-primary me-2"></i>
                                        <strong><?php echo htmlspecialchars($doc['document_name']); ?></strong>
                                        <?php if ($doc['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($doc['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary text-uppercase"><?php echo $doc['file_type']; ?></span>
                                    </td>
                                    <td><?php echo format_file_size($doc['file_size']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['uploaded_by_name']); ?></td>
                                    <td><?php echo format_date($doc['uploaded_at']); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="download_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-primary" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <a href="preview_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-info" title="Preview" target="_blank">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($_SESSION['user_id'] == $doc['uploaded_by'] || $_SESSION['user_role'] == 'admin'): ?>
                                            <a href="delete_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-danger" title="Delete" 
                                               onclick="return confirm('Are you sure you want to delete this document?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                            <?php endif; ?>
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
                        <h5 class="text-muted mt-3">No documents in this folder</h5>
                        <p class="text-muted">Upload the first document to this folder.</p>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                            <i class="bi bi-upload me-2"></i>Upload Document
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Document Modal (for this specific folder) -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="department_docs" enctype="multipart/form-data">
                <input type="hidden" name="folder_id" value="<?php echo $folder_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Upload to: <?php echo htmlspecialchars($folder['folder_name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Document Name</label>
                            <input type="text" class="form-control" name="document_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Folder</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($folder['folder_name']); ?>" disabled>
                            <small class="text-muted">[<?php echo htmlspecialchars($folder['department']); ?>]</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description (Optional)</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Select File</label>
                            <input type="file" class="form-control" name="document_file" required 
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.zip">
                            <div class="form-text">
                                Allowed file types: PDF, Word, Excel, PowerPoint, images, ZIP (Max: 50MB)
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_document" class="btn btn-success">Upload Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include '../includes/footer.php';
?>