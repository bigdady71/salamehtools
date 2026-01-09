<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';

// Admin only
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied - Admins only');
}

$pdo = db();
$created = false;
$error = null;
$tableExists = false;

// Check if table exists
try {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'sales_rep_expenses'");
    $tableExists = $checkStmt->rowCount() > 0;
} catch (Exception $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_table'])) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_rep_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sales_rep_id INT NOT NULL,
                category VARCHAR(50) NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'USD',
                expense_date DATE NOT NULL,
                description TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                admin_note TEXT NULL,
                approved_by INT NULL,
                approved_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,

                INDEX idx_sales_rep (sales_rep_id),
                INDEX idx_status (status),
                INDEX idx_expense_date (expense_date),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $created = true;
        $tableExists = true;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Expenses Table</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            margin-top: 0;
            color: #333;
        }
        .success {
            background: #d4edda;
            border: 1px solid #28a745;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #dc3545;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #17a2b8;
            color: #0c5460;
            padding: 12px;
            border-radius: 4px;
            margin: 20px 0;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            font-weight: 600;
        }
        button:hover {
            background: #0056b3;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #007bff;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>💵 Setup Expenses Table</h1>

        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($created): ?>
            <div class="success">
                <strong>✓ Table Created Successfully!</strong><br>
                The <code>sales_rep_expenses</code> table has been created and is ready to use.
            </div>
        <?php endif; ?>

        <?php if ($tableExists): ?>
            <div class="info">
                <strong>ℹ Table Already Exists</strong><br>
                The <code>sales_rep_expenses</code> table already exists in the database.
            </div>
            <p>Sales representatives can now track their expenses at:</p>
            <p><strong>Sales Portal → My Expenses</strong></p>
        <?php else: ?>
            <div class="info">
                <strong>Table Not Found</strong><br>
                The <code>sales_rep_expenses</code> table needs to be created.
            </div>

            <h3>Table Structure:</h3>
            <pre>
Fields:
- id (Primary Key)
- sales_rep_id (Foreign Key to users)
- category (fuel, meals, maintenance, etc.)
- amount (Decimal)
- currency (USD or LBP)
- expense_date (Date)
- description (Text)
- status (pending, approved, rejected)
- admin_note (Text, nullable)
- approved_by (Foreign Key to users, nullable)
- approved_at (DateTime, nullable)
- created_at (Timestamp)
- updated_at (Timestamp)
            </pre>

            <form method="POST" onsubmit="return confirm('Create the expenses table?');">
                <button type="submit" name="create_table" value="1">Create Table Now</button>
            </form>
        <?php endif; ?>

        <a href="../sales/expenses.php" class="back-link">→ Go to Expenses Page</a><br>
        <a href="../admin/dashboard.php" class="back-link">← Back to Admin Dashboard</a>
    </div>
</body>
</html>
