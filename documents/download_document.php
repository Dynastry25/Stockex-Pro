<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Allow access to all authenticated users
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
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
        
        if ($document && file_exists($document['file_path'])) {
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $document['document_name'] . '.' . $document['file_type'] . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($document['file_path']));
            
            // Clear any output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            readfile($document['file_path']);
            exit;
        }
    }
}

// If download fails, redirect back with error
header('Location: department_docs.php?error=File not found or cannot be downloaded');
exit;
?>