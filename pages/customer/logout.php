<?php

declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear session data
$_SESSION = [];

// Delete the session cookie
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

// Destroy session
session_destroy();

// Calculate base path dynamically (works on both local and Hostinger)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';
$pos = strpos($scriptPath, '/pages/');
if ($pos !== false) {
    $basePath = substr($scriptPath, 0, $pos);
}

// Redirect to main login page
header('Location: ' . $basePath . '/pages/login.php?logout=success');
exit;