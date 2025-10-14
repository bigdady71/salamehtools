<?php
require_once __DIR__ . '/auth.php';

function require_login(): void {
  if (!auth_user()) {
    header('Location: ../login.php'); // from /pages/<area>/*
    exit;
  }
}
