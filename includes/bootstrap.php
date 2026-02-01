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

// Set timezone
date_default_timezone_set('Asia/Beirut');

// Configure session for long-term login
if (session_status() === PHP_SESSION_NONE) {
    // Use custom session name to avoid conflicts
    session_name('SALAMEH_SESS');

    // Detect HTTPS
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // Make session cookie accessible across entire site
    session_set_cookie_params([
        'lifetime' => 604800,   // 7 days
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    // Regenerate session ID periodically for security (every 30 minutes)
    if (!isset($_SESSION['_last_regeneration'])) {
        $_SESSION['_last_regeneration'] = time();
    } elseif (time() - $_SESSION['_last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }
}
