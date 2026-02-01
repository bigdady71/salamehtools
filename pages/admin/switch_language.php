<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/lang.php';

// Require login
require_login();

// Get requested language
$lang = $_GET['lang'] ?? '';

// Validate language
if (!in_array($lang, ['en', 'ar'])) {
    http_response_code(400);
    echo 'Invalid language';
    exit;
}

// Set the language
set_user_language($lang);

// Calculate base path dynamically
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';
$pos = strpos($scriptPath, '/pages/');
if ($pos !== false) {
    $basePath = substr($scriptPath, 0, $pos);
}

// Redirect back to referer or dashboard
$redirect = $_SERVER['HTTP_REFERER'] ?? $basePath . '/pages/sales/dashboard.php';
header('Location: ' . $redirect);
exit;
