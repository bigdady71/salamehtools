<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy session
$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Calculate base path dynamically (works on both local and Hostinger)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';
$pos = strpos($scriptPath, '/pages/');
if ($pos !== false) {
    $basePath = substr($scriptPath, 0, $pos);
}

// Redirect to login
header('Location: ' . $basePath . '/pages/login.php');
exit;
