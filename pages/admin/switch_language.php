<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/lang.php';

// Require login
require_login();

// Get requested language
$lang = $_GET['lang'] ?? '';

// Validate language
if (!in_array($lang, ['en', 'ar'])) {
    http_response_code(400);
    echo 'Invalid language';
    exit;
}

// Set the language
set_user_language($lang);

// Redirect back to referer or dashboard
$redirect = $_SERVER['HTTP_REFERER'] ?? '/salamehtools/pages/sales/dashboard.php';
header('Location: ' . $redirect);
exit;
