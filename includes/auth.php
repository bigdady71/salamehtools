<?php
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie settings before starting session
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // Set session name to avoid conflicts
    session_name('SALAMEH_SESS');

    session_set_cookie_params([
        'lifetime' => 86400 * 7,  // 7 days (persistent session)
        'path' => '/',            // Available across entire site
        'domain' => '',           // Current domain only
        'secure' => $isSecure,    // Only send over HTTPS if on HTTPS
        'httponly' => true,       // Prevent JavaScript access
        'samesite' => 'Lax'       // Prevent CSRF while allowing normal navigation
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

if (!function_exists('auth_user')) {
    function auth_user()
    {
        return $_SESSION['user'] ?? null;
    }
}
