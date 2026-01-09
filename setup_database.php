<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/guard.php';

// Admin only
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied - Admins only');
}

$pdo = db();
$results = [];
$errors = [];

// Define all tables that need to be created
$tables = [
    'sales_rep_expenses' => "
        CREATE TABLE IF NOT EXISTS sales_rep_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sales_rep_id INT UNSIGNED NOT NULL,
            category VARCHAR(50) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            expense_date DATE NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'approved',
            admin_note TEXT NULL,
            approved_by INT UNSIGNED NULL,
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
    "
];

// Process table creation if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_tables'])) {
    foreach ($tables as $tableName => $createSQL) {
        try {
            $pdo->exec($createSQL);
            $results[] = "✓ Table '$tableName' created successfully (or already exists)";
        } catch (Exception $e) {
            $errors[] = "✗ Error creating table '$tableName': " . $e->getMessage();
        }
    }
}

// Check which tables exist
$existingTables = [];
foreach ($tables as $tableName => $createSQL) {
    try {
        $checkStmt = $pdo->query("SHOW TABLES LIKE '$tableName'");
        $existingTables[$tableName] = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $existingTables[$tableName] = false;
    }
}

$allTablesExist = !in_array(false, $existingTables, true);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h1 {
            margin-top: 0;
            color: #333;
        }
        h2 {
            color: #555;
            font-size: 18px;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #28a745;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #dc3545;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #17a2b8;
            color: #0c5460;
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
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
            margin-top: 15px;
        }
        button:hover {
            background: #0056b3;
        }
        button:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        .table-status {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .table-status:last-child {
            border-bottom: none;
        }
        .table-name {
            flex: 1;
            font-weight: 500;
            font-family: monospace;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-exists {
            background: #d4edda;
            color: #155724;
        }
        .status-missing {
            background: #f8d7da;
            color: #721c24;
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
        ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        li {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🛠️ Database Setup</h1>

        <?php if (!empty($results)): ?>
            <div class="success">
                <strong>Setup Complete!</strong>
                <?php foreach ($results as $result): ?>
                    <div><?= htmlspecialchars($result, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2>Table Status:</h2>
        <?php foreach ($existingTables as $tableName => $exists): ?>
            <div class="table-status">
                <span class="table-name"><?= htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="status-badge <?= $exists ? 'status-exists' : 'status-missing' ?>">
                    <?= $exists ? '✓ Exists' : '✗ Missing' ?>
                </span>
            </div>
        <?php endforeach; ?>

        <?php if (!$allTablesExist): ?>
            <div class="warning">
                <strong>⚠ Missing Tables Detected</strong><br>
                Some required tables are missing. Click the button below to create them.
            </div>

            <h2>Tables to be created:</h2>
            <ul>
                <li><strong>sales_rep_expenses</strong> - Tracks sales representative expenses (fuel, meals, maintenance, etc.)</li>
            </ul>

            <form method="POST" onsubmit="return confirm('Create all missing tables?');">
                <button type="submit" name="setup_tables" value="1">
                    🚀 Create All Missing Tables
                </button>
            </form>
        <?php else: ?>
            <div class="info">
                <strong>✓ All Tables Exist</strong><br>
                All required database tables are present and ready to use.
            </div>
        <?php endif; ?>

        <a href="pages/admin/dashboard.php" class="back-link">← Back to Admin Dashboard</a><br>
        <a href="pages/sales/expenses.php" class="back-link">→ Go to Expenses Page</a>
    </div>
</body>
</html>
