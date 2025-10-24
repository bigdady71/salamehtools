
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = trim($_POST['identifier'] ?? '');
  $pw = $_POST['password'] ?? '';
  if ($id !== '' && $pw !== '') {
    $pdo = db();
    $stmt = $pdo->prepare(
      "SELECT id, name, role, is_active, password_hash
       FROM users
       WHERE (email = :id OR phone = :id OR name = :id)
       LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    // plain-text check (temporary, dev only)
    if ($u && (int)$u['is_active'] === 1 && $pw === $u['password_hash']) {
      $_SESSION['user'] = [
        'id'   => $u['id'],
        'name' => $u['name'],
        'role' => $u['role']
      ];
      // redirect by role
      switch ($u['role']) {
        case 'admin':
          $dest = 'admin/dashboard.php';
          break;
        case 'accountant':
          $dest = 'accounting/dashboard.php';
          break;
        case 'sales_rep':
          $dest = 'sales/dashboard.php';
          break;
        case 'viewer':
          $dest = 'customer/dashboard.php';
          break;
        default:
          $dest = 'customer/dashboard.php';
      }
      header('Location: ' . $dest);
      exit;
    }
    $error = 'Invalid credentials or inactive account.';
  } else {
    $error = 'Both fields are required.';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login | Salameh Tools</title>
  <link rel="stylesheet" href="../css/app.css">
  <link rel="stylesheet" href="../css/login.css">
  <style>
    body {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      background: #0c1022;
      font-family: sans-serif;
    }
    .ring {
      position: relative;
      width: 380px;
      height: 380px;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .ring i {
      position: absolute;
      inset: 0;
      border: 2px solid var(--clr);
      transition: 0.5s;
      animation: rotate 6s linear infinite;
      border-radius: 50%;
    }
    .ring i:nth-child(2) {
      animation-delay: -2s;
    }
    .ring i:nth-child(3) {
      animation-delay: -4s;
    }
    @keyframes rotate {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .login {
      position: absolute;
      width: 320px;
      background: #1c1f2b;
      border-radius: 10px;
      box-shadow: 0 0 25px rgba(0,0,0,0.3);
      padding: 40px 30px;
      color: #fff;
    }
    .login h2 {
      text-align: center;
      margin-bottom: 20px;
    }
    .inputBx {
      position: relative;
      width: 100%;
      margin-bottom: 20px;
    }
    .inputBx input {
      width: 100%;
      padding: 10px 15px;
      border: none;
      outline: none;
      border-radius: 5px;
      background: #262a3e;
      color: #fff;
      font-size: 16px;
    }
    .inputBx input[type="submit"] {
      background: #00ff88;
      color: #000;
      font-weight: 600;
      cursor: pointer;
      transition: 0.3s;
    }
    .inputBx input[type="submit"]:hover {
      background: #0affb0;
    }
    .links {
      display: flex;
      justify-content: space-between;
      font-size: 14px;
    }
    .links a {
      color: #00ff88;
      text-decoration: none;
    }
    .links a:hover {
      text-decoration: underline;
    }
    .error-msg {
      color: #ff4b4b;
      text-align: center;
      margin-top: 10px;
    }
  </style>
</head>
<body>
  <div class="ring">
    <i style="--clr:#00ff0a;"></i>
    <i style="--clr:#ff0057;"></i>
    <i style="--clr:#fffd44;"></i>
    <div class="login">
      <h2>Login</h2>
      <form method="post">
        <div class="inputBx">
          <input type="text" name="identifier" placeholder="Email, Phone, or Name" required>
        </div>
        <div class="inputBx">
          <input type="password" name="password" placeholder="Password" required>
        </div>
        <div class="inputBx">
          <input type="submit" value="Sign in">
        </div>
        <?php if ($error): ?>
          <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</body>
</html>
