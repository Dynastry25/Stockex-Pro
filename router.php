<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If the file exists, serve it directly
$filePath = __DIR__ . $uri;
if ($uri !== '/' && is_file($filePath)) {
    return false;
}

// Map /path/file -> /path/file.php if it exists
$phpPath = __DIR__ . $uri . '.php';
if (is_file($phpPath)) {
    // Redirect /file.php -> /file (301)
    if (str_ends_with($uri, '.php')) {
        $withoutExt = substr($uri, 0, -4);
        header('Location: ' . $withoutExt, true, 301);
        exit;
    }
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
