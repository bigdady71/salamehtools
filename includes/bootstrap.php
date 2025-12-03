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

// Configure session for long-term login (30 days)
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie to last 30 days (2592000 seconds)
    ini_set('session.cookie_lifetime', '2592000');
    ini_set('session.gc_maxlifetime', '2592000');

    // Make session cookie accessible across entire site
    session_set_cookie_params([
        'lifetime' => 2592000,  // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => false,  // Set to true if using HTTPS
        'httponly' => true,  // Prevent JavaScript access
        'samesite' => 'Lax'  // CSRF protection
    ]);

    session_start();

    // Regenerate session ID periodically for security (once per day)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 86400) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}
