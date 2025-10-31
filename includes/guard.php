<?php
require_once __DIR__ . '/auth.php';

function require_login(): void {
  if (!auth_user()) {
    header('Location: ../login.php'); // from /pages/<area>/*
    exit;
  }
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  $token = csrf_token();
  return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(string $token): bool {
  if ($token === '' || !isset($_SESSION['csrf_token'])) {
    return false;
  }
  return hash_equals($_SESSION['csrf_token'], $token);
}
