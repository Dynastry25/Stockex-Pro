<?php
/**
 * Router for PHP built-in server (`php -S`) to mirror the Apache .htaccess
 * URL rewriting behavior so localhost URLs match production:
 *
 *   /trader/settlement.php  -> 301->  /trader/settlement
 *   /trader/settlement      ->  serve trader/settlement.php
 *   /some/nonexistent       ->  fall back to index.php
 *
 * The `/api/` path never strips `.php` (stripping it there would turn
 * POST requests into GET, breaking the API) — matches .htaccess.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Mirror .htaccess: /file.php -> /file (301), but NEVER for /api/ paths
if (str_ends_with($uri, '.php') && stripos($uri, '/api/') !== 0 && stripos($uri, '/api') === false) {
    $withoutExt = substr($uri, 0, -4);
    header('Location: ' . $withoutExt, true, 301);
    exit;
}

// Skip requests for real files (stylesheets, scripts, images, uploaded files...)
$filePath = __DIR__ . $uri;
if ($uri !== '/' && is_file($filePath)) {
    return false;
}

// Directory index handling: /trader/ -> /trader/index.php
if ($uri !== '/' && is_dir($filePath)) {
    $dirIndex = rtrim($filePath, '/') . '/index.php';
    if (is_file($dirIndex)) {
        chdir(dirname($dirIndex));
        require $dirIndex;
        return true;
    }
}

// Map /path/file -> /path/file.php if it exists
$phpPath = __DIR__ . $uri . '.php';
if (is_file($phpPath)) {
    chdir(dirname($phpPath));
    require $phpPath;
    return true;
}

// Fallback to index.php
$indexPath = __DIR__ . '/index.php';
if (is_file($indexPath)) {
    chdir(dirname($indexPath));
    require $indexPath;
    return true;
}

return false;
