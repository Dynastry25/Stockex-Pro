<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid ID');
}

try {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT file_data, file_name, mime_type FROM receipt_files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file || empty($file['file_data'])) {
        http_response_code(404);
        exit('File not found');
    }

    $mime = $file['mime_type'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($file['file_data']));
    header('Content-Disposition: inline; filename="' . $file['file_name'] . '"');
    header('Cache-Control: public, max-age=86400');
    echo $file['file_data'];
} catch (Exception $e) {
    http_response_code(500);
    exit('Error serving file');
}
