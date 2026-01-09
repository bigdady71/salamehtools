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
$fixed = false;
$error = null;
$info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_counter'])) {
    try {
        $pdo->beginTransaction();

        // Get the highest invoice number from the invoices table
        $stmt = $pdo->query("
            SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_num
            FROM invoices
            WHERE invoice_number LIKE 'INV-%'
        ");
        $maxNum = (int)$stmt->fetchColumn();

        $info['current_max'] = 'INV-' . str_pad((string)$maxNum, 6, '0', STR_PAD_LEFT);

        // Get current counter value
        $counterStmt = $pdo->query("SELECT current_value FROM counters WHERE name = 'invoice_number'");
        $currentCounter = (int)$counterStmt->fetchColumn();
        $info['old_counter'] = $currentCounter;

        // Update the counter to be at least as high as the max invoice number
        $updateStmt = $pdo->prepare("
            UPDATE counters
            SET current_value = GREATEST(current_value, :value)
            WHERE name = 'invoice_number'
        ");
        $updateStmt->execute([':value' => $maxNum]);

        // Verify the counter
        $checkStmt = $pdo->query("SELECT current_value FROM counters WHERE name = 'invoice_number'");
        $newCounter = (int)$checkStmt->fetchColumn();
        $info['new_counter'] = $newCounter;
        $info['next_invoice'] = 'INV-' . str_pad((string)($newCounter + 1), 6, '0', STR_PAD_LEFT);

        $pdo->commit();
        $fixed = true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get current status
try {
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_num
        FROM invoices
        WHERE invoice_number LIKE 'INV-%'
    ");
    $currentMax = (int)$stmt->fetchColumn();

    $counterStmt = $pdo->query("SELECT current_value FROM counters WHERE name = 'invoice_number'");
    $counterValue = (int)$counterStmt->fetchColumn();

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Invoice Counter</title>
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
        .status {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .status-row {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        .status-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: 600;
            color: #495057;
        }
        .value {
            font-family: monospace;
            color: #212529;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 12px;
            border-radius: 4px;
            margin: 20px 0;
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
    </style>
</head>
<body>
    <div class="card">
        <h1>🔧 Fix Invoice Counter</h1>

        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($fixed): ?>
            <div class="success">
                <strong>✓ Counter Fixed Successfully!</strong>
                <div style="margin-top: 10px;">
                    <div>Old counter value: <?= $info['old_counter'] ?></div>
                    <div>New counter value: <?= $info['new_counter'] ?></div>
                    <div>Next invoice will be: <strong><?= htmlspecialchars($info['next_invoice'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$error): ?>
            <div class="status">
                <h3 style="margin-top: 0;">Current Status</h3>
                <div class="status-row">
                    <span class="label">Highest invoice in database:</span>
                    <span class="value">INV-<?= str_pad((string)$currentMax, 6, '0', STR_PAD_LEFT) ?></span>
                </div>
                <div class="status-row">
                    <span class="label">Current counter value:</span>
                    <span class="value"><?= $counterValue ?></span>
                </div>
                <div class="status-row">
                    <span class="label">Next invoice number:</span>
                    <span class="value">INV-<?= str_pad((string)($counterValue + 1), 6, '0', STR_PAD_LEFT) ?></span>
                </div>
            </div>

            <?php if ($counterValue < $currentMax): ?>
                <div class="warning">
                    <strong>⚠ Counter Out of Sync!</strong><br>
                    The counter (<?= $counterValue ?>) is behind the highest invoice number (<?= $currentMax ?>).<br>
                    This will cause duplicate key errors when creating new invoices.<br>
                    Click the button below to fix this issue.
                </div>

                <form method="POST" onsubmit="return confirm('This will update the invoice counter. Continue?');">
                    <button type="submit" name="fix_counter" value="1">Fix Counter Now</button>
                </form>
            <?php else: ?>
                <div class="success">
                    <strong>✓ Counter is OK</strong><br>
                    The counter is in sync with the database. No action needed.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <a href="../admin/dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
