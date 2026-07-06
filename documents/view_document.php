<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow access to all authenticated users
if (!isset($_SESSION['user_id'])) {
    redirect('auth/login.php');
}

$db = getDBConnection();

if (isset($_GET['id'])) {
    $document_id = (int)$_GET['id'];
    
    if ($document_id) {
        // Get document details
        $stmt = $db->prepare("
            SELECT d.*, df.folder_name, df.department 
            FROM documents d 
            JOIN document_folders df ON d.folder_id = df.id 
            WHERE d.id = ?
        ");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch();
        
        if ($document && $document['file_type'] === 'pdf' && file_exists($document['file_path'])) {
            // Display PDF in browser
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>View PDF: <?php echo htmlspecialchars($document['document_name']); ?></title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
                <style>
                    body { margin: 0; padding: 20px; background: #f8f9fa; }
                    .pdf-container { max-width: 100%; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .pdf-header { padding: 20px; border-bottom: 1px solid #dee2e6; background: #fff; border-radius: 8px 8px 0 0; }
                    .pdf-frame { width: 100%; height: calc(100vh - 200px); border: none; border-radius: 0 0 8px 8px; }
                </style>
            </head>
            <body>
                <div class="pdf-container">
                    <div class="pdf-header">
                        <h4><?php echo htmlspecialchars($document['document_name']); ?></h4>
                        <p class="text-muted mb-2">
                            Folder: <?php echo htmlspecialchars($document['folder_name']); ?> | 
                            Department: <?php echo htmlspecialchars($document['department']); ?>
                        </p>
                        <a href="download_document?id=<?php echo $document['id']; ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-download"></i> Download
                        </a>
                        <a href="department_docs" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Back to Documents
                        </a>
                    </div>
                    <iframe 
                        src="<?php echo $document['file_path']; ?>" 
                        class="pdf-frame"
                        title="PDF Document: <?php echo htmlspecialchars($document['document_name']); ?>"
                    >
                        <p>Your browser does not support PDF viewing. 
                           <a href="download_document?id=<?php echo $document['id']; ?>">Download the PDF instead.</a>
                        </p>
                    </iframe>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

// If view fails, redirect back with error
header('Location: department_docs?error=PDF not found or cannot be viewed');
exit;
?>