<?php
require_once __DIR__ . '/../../includes/auth.php';
$user = auth_user();
if ($user) {
  header('Location: /' . $user['role'] . '/dashboard');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Salameh Tools System</title>
  <link rel="stylesheet" href="/css/app.css">
  <style>
    .hero {text-align:center;margin-top:10vh;}
    .hero h1{font-size:2.2rem;color:#222;}
    .hero p{color:#666;font-size:1.1rem;margin-bottom:30px;}
    .btn{background:#0a58ca;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;}
    .btn:hover{background:#0048a5;}
    footer{text-align:center;margin-top:10vh;color:#999;font-size:0.9rem;}
  </style>
</head>
<body>
  <div class="hero">
    <h1>Welcome to <strong>Salameh Tools</strong> Internal System</h1>
    <p>Manage orders, warehouse operations, and invoices — all in one platform.</p>
    <a href="/pages/public/login.php" class="btn">Login to Continue</a>
  </div>

  <footer>© <?= date('Y') ?> Salameh Tools — Internal System</footer>
</body>
</html>
