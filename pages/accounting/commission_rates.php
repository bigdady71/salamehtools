<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Check if commission tables exist
$tablesExist = false;
try {
    $pdo->query("SELECT 1 FROM commission_rates LIMIT 1");
    $tablesExist = true;
} catch (PDOException $e) {
    // Tables don't exist yet
}

// Handle POST actions (only if tables exist)
if ($tablesExist && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid security token.');
        header('Location: commission_rates.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_default') {
        $directRate = max(0, min(100, (float)($_POST['direct_rate'] ?? 4)));
        $assignedRate = max(0, min(100, (float)($_POST['assigned_rate'] ?? 4)));

        // Update or insert default direct_sale rate
        $stmt = $pdo->prepare("
            UPDATE commission_rates
            SET rate_percentage = :rate, effective_from = CURDATE()
            WHERE sales_rep_id IS NULL AND commission_type = 'direct_sale'
        ");
        $stmt->execute([':rate' => $directRate]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO commission_rates (sales_rep_id, commission_type, rate_percentage, effective_from, created_by)
                VALUES (NULL, 'direct_sale', :rate, CURDATE(), :user_id)
            ");
            $stmt->execute([':rate' => $directRate, ':user_id' => $user['id']]);
        }

        // Update or insert default assigned_customer rate
        $stmt = $pdo->prepare("
            UPDATE commission_rates
            SET rate_percentage = :rate, effective_from = CURDATE()
            WHERE sales_rep_id IS NULL AND commission_type = 'assigned_customer'
        ");
        $stmt->execute([':rate' => $assignedRate]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO commission_rates (sales_rep_id, commission_type, rate_percentage, effective_from, created_by)
                VALUES (NULL, 'assigned_customer', :rate, CURDATE(), :user_id)
            ");
            $stmt->execute([':rate' => $assignedRate, ':user_id' => $user['id']]);
        }

        flash('success', 'Default commission rates updated.');

    } elseif ($action === 'add_override') {
        $salesRepId = (int)($_POST['sales_rep_id'] ?? 0);
        $commissionType = $_POST['commission_type'] ?? '';
        $rate = max(0, min(100, (float)($_POST['rate'] ?? 0)));
        $effectiveFrom = $_POST['effective_from'] ?? date('Y-m-d');

        if ($salesRepId <= 0) {
            flash('error', 'Please select a sales rep.');
        } elseif (!in_array($commissionType, ['direct_sale', 'assigned_customer'])) {
            flash('error', 'Invalid commission type.');
        } else {
            // End any existing rate for this rep/type
            $stmt = $pdo->prepare("
                UPDATE commission_rates
                SET effective_to = DATE_SUB(:effective_from, INTERVAL 1 DAY)
                WHERE sales_rep_id = :rep_id AND commission_type = :type AND effective_to IS NULL
            ");
            $stmt->execute([':effective_from' => $effectiveFrom, ':rep_id' => $salesRepId, ':type' => $commissionType]);

            // Insert new rate
            $stmt = $pdo->prepare("
                INSERT INTO commission_rates (sales_rep_id, commission_type, rate_percentage, effective_from, created_by)
                VALUES (:rep_id, :type, :rate, :effective_from, :user_id)
            ");
            $stmt->execute([
                ':rep_id' => $salesRepId,
                ':type' => $commissionType,
                ':rate' => $rate,
                ':effective_from' => $effectiveFrom,
                ':user_id' => $user['id'],
            ]);

            flash('success', 'Sales rep rate override added.');
        }

    } elseif ($action === 'delete_override') {
        $rateId = (int)($_POST['rate_id'] ?? 0);
        if ($rateId > 0) {
            $stmt = $pdo->prepare("DELETE FROM commission_rates WHERE id = :id AND sales_rep_id IS NOT NULL");
            $stmt->execute([':id' => $rateId]);
            flash('success', 'Rate override deleted.');
        }
    }

    header('Location: commission_rates.php');
    exit;
}

// Initialize defaults
$defaultRates = [
    'direct_sale' => 4.00,
    'assigned_customer' => 4.00,
];
$overrides = [];

if ($tablesExist) {
    // Get default rates
    $defaultRatesStmt = $pdo->query("
        SELECT commission_type, rate_percentage
        FROM commission_rates
        WHERE sales_rep_id IS NULL
        AND (effective_to IS NULL OR effective_to >= CURDATE())
        ORDER BY effective_from DESC
    ");
    $defaultRatesRaw = $defaultRatesStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($defaultRatesRaw as $row) {
        $defaultRates[$row['commission_type']] = (float)$row['rate_percentage'];
    }

    // Get sales rep overrides
    $overridesStmt = $pdo->query("
        SELECT
            cr.id,
            cr.sales_rep_id,
            u.name as sales_rep_name,
            cr.commission_type,
            cr.rate_percentage,
            cr.effective_from,
            cr.effective_to,
            cr.created_at
        FROM commission_rates cr
        JOIN users u ON u.id = cr.sales_rep_id
        WHERE cr.sales_rep_id IS NOT NULL
        AND (cr.effective_to IS NULL OR cr.effective_to >= CURDATE())
        ORDER BY u.name, cr.commission_type
    ");
    $overrides = $overridesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get sales reps for dropdown
$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' AND is_active = 1 ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

accounting_render_layout_start([
    'title' => 'Commission Rates',
    'heading' => 'Commission Rates',
    'subtitle' => 'Configure default and sales rep-specific commission rates',
    'active' => 'commission_rates',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());

if (!$tablesExist): ?>
<div class="card" style="background: #fef3c7; border-color: #fde68a; margin-bottom: 20px;">
    <h2 style="color: #92400e;">Migration Required</h2>
    <p style="color: #92400e;">The commission tables have not been created yet. Please run the migration to enable commission rate management:</p>
    <pre style="background: #fffbeb; padding: 12px; border-radius: 6px; margin: 12px 0; color: #78350f;">
SOURCE c:/xampp/htdocs/salamehtools/migrations/accounting_module_UP.sql;</pre>
    <p style="color: #92400e; margin-top: 12px;">After running the migration, refresh this page.</p>
</div>
<?php endif; ?>

<?php if ($tablesExist): ?>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <div class="card">
        <h2>Default Commission Rates</h2>
        <p class="text-muted mb-2">These rates apply to all sales reps unless overridden.</p>

        <form method="POST" style="display: flex; flex-direction: column; gap: 16px; margin-top: 20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_default">

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Direct Sale Rate (%)</label>
                <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 8px;">Rate when a sales rep makes a sale directly.</p>
                <input type="number" name="direct_rate" step="0.01" min="0" max="100"
                       value="<?= number_format($defaultRates['direct_sale'], 2) ?>"
                       class="filter-input" style="width: 120px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Assigned Customer Rate (%)</label>
                <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 8px;">Rate when an assigned customer places their own order (and no rep made the sale).</p>
                <input type="number" name="assigned_rate" step="0.01" min="0" max="100"
                       value="<?= number_format($defaultRates['assigned_customer'], 2) ?>"
                       class="filter-input" style="width: 120px;">
            </div>

            <button type="submit" class="btn btn-primary" style="align-self: flex-start;">Update Default Rates</button>
        </form>
    </div>

    <div class="card">
        <h2>Add Rate Override</h2>
        <p class="text-muted mb-2">Set a custom rate for a specific sales rep.</p>

        <form method="POST" style="display: flex; flex-direction: column; gap: 16px; margin-top: 20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_override">

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Sales Rep</label>
                <select name="sales_rep_id" class="filter-input" style="width: 100%;" required>
                    <option value="">Select sales rep...</option>
                    <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= (int)$rep['id'] ?>"><?= htmlspecialchars($rep['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Commission Type</label>
                <select name="commission_type" class="filter-input" style="width: 100%;" required>
                    <option value="direct_sale">Direct Sale</option>
                    <option value="assigned_customer">Assigned Customer</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Rate (%)</label>
                    <input type="number" name="rate" step="0.01" min="0" max="100" value="4.00"
                           class="filter-input" style="width: 100%;" required>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Effective From</label>
                    <input type="date" name="effective_from" value="<?= date('Y-m-d') ?>"
                           class="filter-input" style="width: 100%;" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="align-self: flex-start;">Add Override</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($tablesExist): ?>
<div class="card" style="margin-top: 24px;">
    <h2>Active Rate Overrides</h2>

    <table>
        <thead>
            <tr>
                <th>Sales Rep</th>
                <th>Type</th>
                <th class="text-center">Rate (%)</th>
                <th>Effective From</th>
                <th>Effective Until</th>
                <th style="width: 100px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($overrides)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">No rate overrides configured. All sales reps use default rates.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($overrides as $override): ?>
                    <tr>
                        <td><?= htmlspecialchars($override['sales_rep_name']) ?></td>
                        <td>
                            <span class="badge badge-<?= $override['commission_type'] === 'direct_sale' ? 'info' : 'success' ?>">
                                <?= $override['commission_type'] === 'direct_sale' ? 'Direct Sale' : 'Assigned Customer' ?>
                            </span>
                        </td>
                        <td class="text-center" style="font-weight: 600;"><?= number_format((float)$override['rate_percentage'], 2) ?>%</td>
                        <td><?= date('M j, Y', strtotime($override['effective_from'])) ?></td>
                        <td><?= $override['effective_to'] ? date('M j, Y', strtotime($override['effective_to'])) : '<span class="text-muted">No end date</span>' ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_override">
                                <input type="hidden" name="rate_id" value="<?= (int)$override['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this rate override?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card" style="margin-top: 24px; background: #f0fdf4;">
    <h2 style="color: #059669;">Commission Rules</h2>
    <ul style="margin: 12px 0 0 20px; color: #065f46; line-height: 1.8;">
        <li><strong>Direct Sale:</strong> When a sales rep makes a sale (order has a sales_rep_id), that rep earns the Direct Sale rate.</li>
        <li><strong>Assigned Customer:</strong> When a customer places their own order (no sales rep on the order) and that customer has an assigned sales rep, the assigned rep earns the Assigned Customer rate.</li>
        <li><strong>Cross-Rep Sale:</strong> If Rep A sells to a customer assigned to Rep B, only Rep A gets the commission (direct seller wins).</li>
        <li><strong>Only one commission per order</strong> - never both rates on the same order.</li>
    </ul>
</div>

<?php
accounting_render_layout_end();
