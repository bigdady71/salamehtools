
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Resolve base path for subdirectory installs (e.g., /salamehtools)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$base = '';
$pos = strpos($scriptPath, '/pages/');
if ($pos !== false) {
    $base = substr($scriptPath, 0, $pos);
} else {
    $dir = rtrim(dirname($scriptPath), '/\\');
    $base = ($dir === '/' || $dir === '\\') ? '' : $dir;
}

// Debug: Show session info if requested
if (isset($_GET['debug'])) {
    echo "<pre>";
    echo "Session ID: " . session_id() . "\n";
    echo "Session Status: " . session_status() . " (2=active)\n";
    echo "Session Save Path: " . (session_save_path() ?: '(default)') . "\n";
    echo "Session Data: " . print_r($_SESSION, true) . "\n";
    echo "Cookies: " . print_r($_COOKIE, true) . "\n";
    echo "</pre>";
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = trim($_POST['identifier'] ?? '');
  $pw = $_POST['password'] ?? '';
  if ($id !== '' && $pw !== '') {
    $pdo = db();

    // First try to login as staff user (users table)
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
          $dest = $base . '/pages/admin/dashboard.php';
          break;
        case 'accountant':
          $dest = $base . '/pages/accounting/dashboard.php';
          break;
        case 'sales_rep':
          $dest = $base . '/pages/sales/dashboard.php';
          break;
        case 'warehouse':
          $dest = $base . '/pages/warehouse/dashboard.php';
          break;
        default:
          $dest = $base . '/pages/customer/dashboard.php';
      }
      header('Location: ' . $dest);
      exit;
    }

    // If not a staff user, try customer login (customers table)
    $customerStmt = $pdo->prepare(
      "SELECT id, name, phone, password_hash, is_active, login_enabled
       FROM customers
       WHERE (phone = :id OR name = :id) AND login_enabled = 1
       LIMIT 1"
    );
    $customerStmt->execute([':id' => $id]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    // plain-text password check for customers
    if ($customer && (int)$customer['is_active'] === 1 && $pw === $customer['password_hash']) {
      $_SESSION['user'] = [
        'id'   => $customer['id'],
        'name' => $customer['name'],
        'role' => 'customer'
      ];

      // Update last login timestamp
      try {
        $updateLoginStmt = $pdo->prepare("UPDATE customers SET last_login_at = NOW() WHERE id = ?");
        $updateLoginStmt->execute([$customer['id']]);
      } catch (PDOException $e) {
        error_log("Last login update failed: " . $e->getMessage());
      }

      header('Location: ' . $base . '/pages/customer/dashboard.php');
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
