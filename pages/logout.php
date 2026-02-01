<?php

declare(strict_types=1);

session_start();

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Calculate base path dynamically (works on both local and Hostinger)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';
$pos = strpos($scriptPath, '/pages/');
if ($pos !== false) {
    $basePath = substr($scriptPath, 0, $pos);
}

// Redirect to login page
header('Location: ' . $basePath . '/pages/login.php');
exit;