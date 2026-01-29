<?php
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie settings before starting session
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,          // Session cookie (expires when browser closes)
        'path' => '/',            // Available across entire site
        'domain' => '',           // Current domain only
        'secure' => $isSecure,    // Only send over HTTPS if on HTTPS
        'httponly' => true,       // Prevent JavaScript access
        'samesite' => 'Lax'       // Prevent CSRF while allowing normal navigation
    ]);

    session_start();
}

if (!function_exists('auth_user')) {
    function auth_user() {
        return $_SESSION['user'] ?? null;
    }
}
