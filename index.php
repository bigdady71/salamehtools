<?php
/**
 * Main entry point - redirects to login page
 * All routing is handled via direct file access or .htaccess rules
 */

// Calculate base path dynamically (works on both local XAMPP and Hostinger)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';

// Check if we're in a subdirectory (like /salamehtools on localhost)
if (strpos($scriptPath, '/index.php') !== false) {
    $basePath = str_replace('/index.php', '', $scriptPath);
}

// Redirect to login page
$loginUrl = $basePath . '/pages/login.php';
header('Location: ' . $loginUrl);
exit;
