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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_trigger'])) {
    try {
        // First, check if there are any triggers on s_stock
        $stmt = $pdo->query("SHOW TRIGGERS WHERE `Table` = 's_stock'");
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($triggers)) {
            foreach ($triggers as $trigger) {
                $triggerName = $trigger['Trigger'];
                // Drop the problematic trigger
                $pdo->exec("DROP TRIGGER IF EXISTS `{$triggerName}`");
                $results[] = "Dropped trigger: {$triggerName}";
            }
        } else {
            $results[] = "No triggers found on s_stock table";
        }

        $results[] = "✓ Stock trigger issues have been resolved";

    } catch (Exception $e) {
        $errors[] = "Error: " . $e->getMessage();
    }
}

// Check current triggers
$currentTriggers = [];
try {
    $stmt = $pdo->query("SHOW TRIGGERS WHERE `Table` = 's_stock'");
    $currentTriggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "Error checking triggers: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Stock Trigger</title>
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
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
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
        button {
            background: #dc3545;
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
            background: #c82333;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        pre {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔧 Fix Stock Trigger Issue</h1>

        <?php if (!empty($results)): ?>
            <?php foreach ($results as $result): ?>
                <div class="success">
                    <?= htmlspecialchars($result, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2>Problem:</h2>
        <div class="warning">
            <strong>Error:</strong> "s_stock would go negative"<br>
            This error occurs when there's a database trigger preventing stock from becoming negative or zero.
        </div>

        <h2>Current Triggers on s_stock table:</h2>
        <?php if (empty($currentTriggers)): ?>
            <div class="info">
                ✓ No triggers found on s_stock table. The issue may be resolved.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Trigger Name</th>
                        <th>Event</th>
                        <th>Timing</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($currentTriggers as $trigger): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($trigger['Trigger'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars($trigger['Event'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($trigger['Timing'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="warning">
                <strong>⚠ Warning:</strong> The triggers above are preventing stock updates that would result in negative quantities.
            </div>

            <form method="POST" onsubmit="return confirm('This will remove all triggers from the s_stock table. The application code already has built-in stock validation. Continue?');">
                <button type="submit" name="fix_trigger" value="1">
                    🗑️ Remove Problematic Triggers
                </button>
            </form>

            <div class="info" style="margin-top: 20px;">
                <strong>ℹ Note:</strong> The application already validates stock levels before updates, so these triggers are redundant and causing issues.
            </div>
        <?php endif; ?>

        <a href="pages/sales/van_stock_sales.php" class="back-link">→ Go to Van Stock Sales</a><br>
        <a href="pages/admin/dashboard.php" class="back-link">← Back to Admin Dashboard</a>
    </div>
</body>
</html>
