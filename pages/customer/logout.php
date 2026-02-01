<?php

declare(strict_types=1);

// Use the same session name as auth.php
session_name('SALAMEH_SESS');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear session data
$_SESSION = [];

// Clear custom session cookie
setcookie('SALAMEH_SESS', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Also clear default PHP session cookie just in case
if (isset($_COOKIE['PHPSESSID'])) {
    setcookie('PHPSESSID', '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Calculate base path dynamically (works on both local and Hostinger)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';
$pos = strpos($scriptPath, '/pages/');
if ($pos !== false) {
    $basePath = substr($scriptPath, 0, $pos);
}

// Prevent caching of this page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Redirect to main login page
header('Location: ' . $basePath . '/pages/login.php?logout=success');
exit;
