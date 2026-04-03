<?php
/**
 * API v1 - Trade Forms Endpoint
 *
 * Lists the available trade forms (SALE ORDER FORM, PURCHASE ORDER FORM)
 * and supports full file download with download count tracking.
 * No authentication required — public endpoint.
 *
 * Usage:
 * GET /api/v1/trade_forms.php               - List all active trade forms
 * GET /api/v1/trade_forms.php?id={id}       - Get a specific form's metadata
 * GET /api/v1/trade_forms.php?id={id}&action=download - Download the form file
 */

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    return true;
});

set_exception_handler(function ($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && $error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
        error_log("Fatal Error on shutdown: " . json_encode($error));
    }
});

require_once __DIR__ . '/../config.php';

error_log("Trade Forms API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

// Only GET is supported
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        sendError('Database connection failed', 500);
    }

    handleGetRequest($db);

} catch (Exception $e) {
    error_log("Top-level exception in trade_forms.php: " . $e->getMessage() . " | " . $e->getTraceAsString());
    sendError('Internal server error: ' . $e->getMessage(), 500);
}

// ---------------------------------------------------------------------------

/**
 * Route all GET requests.
 */
function handleGetRequest($db)
{
    $id     = isset($_GET['id'])     ? intval($_GET['id'])               : null;
    $action = isset($_GET['action']) ? sanitizeInput($_GET['action'])    : null;

    if ($id !== null && $action === 'download') {
        handleDownload($db, $id);
    } elseif ($id !== null) {
        handleGetById($db, $id);
    } else {
        handleGetAll($db);
    }
}

/**
 * GET /api/v1/trade_forms.php
 * Return metadata for all active trade forms.
 */
function handleGetAll($db)
{
    $stmt = $db->prepare("
        SELECT id, form_name, form_description, file_name, file_type,
               file_size, download_count, uploaded_by_username, created_at
        FROM forms
        WHERE is_active = 1
        ORDER BY form_name ASC
    ");
    $stmt->execute();
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($forms as &$form) {
        $form['file_size_formatted'] = formatFileSize((int)$form['file_size']);
        $form['download_url']        = buildDownloadUrl($form['id']);
    }
    unset($form);

    logAPIRequest('/api/v1/trade_forms', 'GET', 200);
    sendResponse([
        'total'  => count($forms),
        'forms'  => $forms,
    ], 200, 'Trade forms retrieved successfully');
}

/**
 * GET /api/v1/trade_forms.php?id={id}
 * Return metadata for a single trade form.
 */
function handleGetById($db, $id)
{
    $stmt = $db->prepare("
        SELECT id, form_name, form_description, file_name, file_type,
               file_size, download_count, uploaded_by_username, created_at
        FROM forms
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        logAPIRequest("/api/v1/trade_forms/{$id}", 'GET', 404);
        sendError('Form not found', 404);
    }

    $form['file_size_formatted'] = formatFileSize((int)$form['file_size']);
    $form['download_url']        = buildDownloadUrl($form['id']);

    logAPIRequest("/api/v1/trade_forms/{$id}", 'GET', 200);
    sendResponse($form, 200, 'Form retrieved successfully');
}

/**
 * GET /api/v1/trade_forms.php?id={id}&action=download
 * Increment download count and stream the file to the client.
 */
function handleDownload($db, $id)
{
    // Fetch form record (including file_path)
    $stmt = $db->prepare("
        SELECT id, form_name, file_name, file_path, file_type
        FROM forms
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        logAPIRequest("/api/v1/trade_forms/{$id}/download", 'GET', 404);
        sendError('Form not found', 404);
    }

    // Resolve the absolute path safely.
    // file_path is stored relative to trader/ (e.g. "../uploads/forms/xxxx_file.pdf").
    // From api/v1/ the uploads/forms directory is two levels up.
    $uploads_base = realpath(__DIR__ . '/../../uploads/forms');

    if ($uploads_base === false) {
        error_log("trade_forms.php: uploads/forms directory not found");
        sendError('File storage directory not found', 500);
    }

    // Use only the basename to prevent path traversal via a crafted file_path value.
    $safe_basename = basename($form['file_path']);
    $abs_path      = $uploads_base . DIRECTORY_SEPARATOR . $safe_basename;
    $real_path     = realpath($abs_path);

    // Guard: resolved path must stay inside the uploads directory.
    if ($real_path === false || strpos($real_path, $uploads_base) !== 0) {
        error_log("trade_forms.php: path traversal attempt for id={$id}, file_path=" . $form['file_path']);
        sendError('Access denied', 403);
    }

    if (!is_file($real_path) || !is_readable($real_path)) {
        error_log("trade_forms.php: file not readable: {$real_path}");
        logAPIRequest("/api/v1/trade_forms/{$id}/download", 'GET', 404);
        sendError('File not found on server', 404);
    }

    // Increment download count
    $upd = $db->prepare("UPDATE forms SET download_count = download_count + 1 WHERE id = ?");
    $upd->execute([$id]);

    logAPIRequest("/api/v1/trade_forms/{$id}/download", 'GET', 200);

    // Stream the file — clear any buffered output from config.php headers first
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $mime = resolveContentType($form['file_type']);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($form['file_name']) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . filesize($real_path));
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    readfile($real_path);
    exit;
}

// ---------------------------------------------------------------------------
// Helpers

/**
 * Map internal file_type to a proper MIME type for the download header.
 */
function resolveContentType($file_type)
{
    switch ($file_type) {
        case 'pdf':   return 'application/pdf';
        case 'excel': return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        case 'word':  return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        default:      return 'application/octet-stream';
    }
}

/**
 * Return a human-readable file size string.
 */
function formatFileSize($bytes)
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Build the download URL for a given form id.
 */
function buildDownloadUrl($id)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/api/v1/trade_forms.php?id=' . intval($id) . '&action=download';
}
