<?php
$path = $_GET['path'] ?? 'public/home';

// Sanitize path - prevent directory traversal attacks
$path = str_replace(['..', "\0"], '', $path);
$path = preg_replace('#/+#', '/', $path);
$path = trim($path, '/');

$file = __DIR__ . '/pages/' . $path . '.php';

if (!is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

require $file;
