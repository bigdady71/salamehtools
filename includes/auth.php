<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function auth_user() {
    return $_SESSION['user'] ?? null;
}
