<?php
/**
 * Vercel Serverless Entrypoint & PHP Request Router
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = ltrim($uri, '/');

if (empty($uri) || $uri === '/') {
    $uri = 'index.php';
}

// Security: Prevent directory traversal
$normalized = str_replace(['..', '\\'], ['', '/'], $uri);
$targetFile = dirname(__DIR__) . '/' . $normalized;

// Support clean URLs without .php extension
if (!file_exists($targetFile) && file_exists($targetFile . '.php')) {
    $targetFile .= '.php';
    $normalized .= '.php';
}

// If a PHP file was requested and exists, execute it
if (file_exists($targetFile) && is_file($targetFile) && pathinfo($targetFile, PATHINFO_EXTENSION) === 'php') {
    $_SERVER['SCRIPT_NAME'] = '/' . $normalized;
    $_SERVER['PHP_SELF'] = '/' . $normalized;
    chdir(dirname(__DIR__));
    require $targetFile;
    exit;
}

// If static asset or upload fallback
if (file_exists($targetFile) && is_file($targetFile)) {
    $mime = mime_content_type($targetFile);
    header('Content-Type: ' . $mime);
    readfile($targetFile);
    exit;
}

// Default fallback to index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/index.php';
