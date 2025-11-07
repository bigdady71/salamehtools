<?php
/**
 * Application Bootstrap
 *
 * Loads Composer autoloader and initializes core services
 */

// Load Composer autoloader
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    die('Composer autoloader not found. Run: composer install');
}
require_once $autoloader;

// Core includes are now autoloaded via composer.json files section
// db.php, auth.php, guard.php, flash.php are automatically loaded

// Initialize error handling (optional - uncomment if needed)
// error_reporting(E_ALL);
// ini_set('display_errors', '0'); // Set to '1' for development
// ini_set('log_errors', '1');
// ini_set('error_log', __DIR__ . '/../storage/logs/php_errors.log');

// Set timezone
date_default_timezone_set('Asia/Beirut');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
