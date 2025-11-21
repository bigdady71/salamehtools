<?php

declare(strict_types=1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy session
$_SESSION = [];
session_destroy();

// Redirect to main login page
header('Location: /pages/login.php?logout=success');
exit;
