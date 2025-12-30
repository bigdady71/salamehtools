<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('auth_user')) {
    function auth_user() {
        return $_SESSION['user'] ?? null;
    }
}
