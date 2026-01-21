<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);
$selectedCustomer = null;

// Load selected customer
if ($selectedCustomerId > 0) {
    $custStmt = $pdo->prepare("SELECT id, name, phone, COALESCE(account_balance_usd, 0) as balance_usd, COALESCE(account_balance_lbp, 0) as balance_lbp FROM customers WHERE id = :id");
    $custStmt->execute([':id' => $selectedCustomerId]);
    $selectedCustomer = $custStmt->fetch(PDO::FETCH_ASSOC);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid security token.');
        header('Location: balance_adjustment.php');
        exit;
    }

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $adjustmentType = $_POST['adjustment_type'] ?? '';
    $amountUsd = (float)($_POST['amount_usd'] ?? 0);
    $amountLbp = (float)($_POST['amount_lbp'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $validTypes = ['credit', 'debit', 'correction', 'write_off', 'opening_balance'];

    if ($customerId <= 0) {
        flash('error', 'Please select a customer.');
    } elseif (!in_array($adjustmentType, $validTypes)) {
        flash('error', 'Invalid adjustment type.');
    } elseif ($amountUsd == 0 && $amountLbp == 0) {
        flash('error', 'Please enter an amount to adjust.');
    } elseif ($reason === '') {
        flash('error', 'Please provide a reason for this adjustment.');
    } else {
        // Get current balances
        $custStmt = $pdo->prepare("SELECT COALESCE(account_balance_usd, 0) as balance_usd, COALESCE(account_balance_lbp, 0) as balance_lbp FROM customers WHERE id = :id");
        $custStmt->execute([':id' => $customerId]);
        $current = $custStmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            flash('error', 'Customer not found.');
            header('Location: balance_adjustment.php');
            exit;
        }

        $prevUsd = (float)$current['balance_usd'];
        $prevLbp = (float)$current['balance_lbp'];

        // Calculate new balances based on type
        // credit = reduce balance (customer paid or credit given)
        // debit = increase balance (customer owes more)
        // correction = direct set
        // write_off = reduce balance
        // opening_balance = direct set

        if ($adjustmentType === 'credit' || $adjustmentType === 'write_off') {
            $newUsd = $prevUsd - abs($amountUsd);
            $newLbp = $prevLbp - abs($amountLbp);
        } elseif ($adjustmentType === 'debit') {
            $newUsd = $prevUsd + abs($amountUsd);
            $newLbp = $prevLbp + abs($amountLbp);
        } else {
            // correction or opening_balance - use the amounts as absolute new values if provided
            $newUsd = $amountUsd;
            $newLbp = $amountLbp;
        }

        try {
            $pdo->beginTransaction();

            // Update customer balance
            $updateStmt = $pdo->prepare("UPDATE customers SET account_balance_usd = :usd, account_balance_lbp = :lbp WHERE id = :id");
            $updateStmt->execute([':usd' => $newUsd, ':lbp' => $newLbp, ':id' => $customerId]);

            // Record adjustment in audit table
            $auditStmt = $pdo->prepare("
                INSERT INTO customer_balance_adjustments
                (customer_id, adjustment_type, amount_usd, amount_lbp, previous_balance_usd, previous_balance_lbp, new_balance_usd, new_balance_lbp, reason, reference_type, notes, performed_by)
                VALUES
                (:customer_id, :type, :amount_usd, :amount_lbp, :prev_usd, :prev_lbp, :new_usd, :new_lbp, :reason, 'manual', :notes, :performed_by)
            ");
            $auditStmt->execute([
                ':customer_id' => $customerId,
                ':type' => $adjustmentType,
                ':amount_usd' => $amountUsd,
                ':amount_lbp' => $amountLbp,
                ':prev_usd' => $prevUsd,
                ':prev_lbp' => $prevLbp,
                ':new_usd' => $newUsd,
                ':new_lbp' => $newLbp,
                ':reason' => $reason,
                ':notes' => $notes,
                ':performed_by' => $user['id'],
            ]);

            $pdo->commit();

            flash('success', 'Balance adjustment recorded successfully.');
            header('Location: customer_balances.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash('error', 'Database error: ' . $e->getMessage());
        }
    }

    header('Location: balance_adjustment.php?customer_id=' . $customerId);
    exit;
}

// Get all customers for dropdown
$customersStmt = $pdo->query("SELECT id, name, phone, COALESCE(account_balance_usd, 0) as balance_usd, COALESCE(account_balance_lbp, 0) as balance_lbp FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent adjustments
$recentStmt = $pdo->query("
    SELECT
        cba.id,
        cba.created_at,
        cba.adjustment_type,
        cba.amount_usd,
        cba.amount_lbp,
        cba.previous_balance_usd,
        cba.new_balance_usd,
        cba.reason,
        c.name as customer_name,
        u.name as performed_by_name
    FROM customer_balance_adjustments cba
    JOIN customers c ON c.id = cba.customer_id
    JOIN users u ON u.id = cba.performed_by
    ORDER BY cba.created_at DESC
    LIMIT 20
");
$recentAdjustments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

accounting_render_layout_start([
    'title' => 'Balance Adjustment',
    'heading' => 'Balance Adjustment',
    'subtitle' => 'Manually adjust customer account balances',
    'active' => 'customer_balances',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div style="display: grid; grid-template-columns: 400px 1fr; gap: 24px;">
    <div class="card">
        <h2>New Adjustment</h2>

        <form method="POST" style="display: flex; flex-direction: column; gap: 16px;">
            <?= csrf_field() ?>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Customer</label>
                <select name="customer_id" class="filter-input" style="width: 100%;" required onchange="updateBalance(this)">
                    <option value="">Select customer...</option>
                    <?php foreach ($customers as $cust): ?>
                        <option value="<?= (int)$cust['id'] ?>"
                                data-balance-usd="<?= (float)$cust['balance_usd'] ?>"
                                data-balance-lbp="<?= (float)$cust['balance_lbp'] ?>"
                                <?= $selectedCustomerId == $cust['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cust['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="current-balance" style="padding: 12px; background: #f9fafb; border-radius: 8px; <?= $selectedCustomer ? '' : 'display: none;' ?>">
                <div class="text-muted" style="font-size: 0.85rem;">Current Balance</div>
                <div style="font-size: 1.1rem; font-weight: 600;">
                    <span id="balance-usd"><?= $selectedCustomer ? format_currency_usd((float)$selectedCustomer['balance_usd']) : '$0.00' ?></span>
                    <span class="text-muted" style="margin: 0 8px;">|</span>
                    <span id="balance-lbp"><?= $selectedCustomer ? format_currency_lbp((float)$selectedCustomer['balance_lbp']) : 'LBP 0' ?></span>
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Adjustment Type</label>
                <select name="adjustment_type" class="filter-input" style="width: 100%;" required>
                    <option value="">Select type...</option>
                    <option value="credit">Credit (Reduce Balance - Payment/Credit)</option>
                    <option value="debit">Debit (Increase Balance - Charge)</option>
                    <option value="correction">Correction (Set Exact Balance)</option>
                    <option value="write_off">Write-off (Reduce Balance)</option>
                    <option value="opening_balance">Opening Balance (Set Initial)</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Amount (USD)</label>
                    <input type="number" name="amount_usd" step="0.01" min="0" class="filter-input" style="width: 100%;" placeholder="0.00">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Amount (LBP)</label>
                    <input type="number" name="amount_lbp" step="1" min="0" class="filter-input" style="width: 100%;" placeholder="0">
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Reason (Required)</label>
                <input type="text" name="reason" class="filter-input" style="width: 100%;" required placeholder="e.g., Cash payment received">
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Notes (Optional)</label>
                <textarea name="notes" class="filter-input" style="width: 100%; height: 80px;" placeholder="Additional notes..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Record Adjustment</button>
        </form>
    </div>

    <div class="card">
        <h2>Recent Adjustments</h2>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th class="text-right">Amount</th>
                    <th>Reason</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentAdjustments)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No adjustments recorded yet</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentAdjustments as $adj): ?>
                        <?php
                        $typeClass = match($adj['adjustment_type']) {
                            'credit', 'write_off' => 'badge-success',
                            'debit' => 'badge-danger',
                            default => 'badge-info'
                        };
                        ?>
                        <tr>
                            <td><?= date('M j, Y H:i', strtotime($adj['created_at'])) ?></td>
                            <td><?= htmlspecialchars($adj['customer_name']) ?></td>
                            <td>
                                <span class="badge <?= $typeClass ?>">
                                    <?= ucfirst(str_replace('_', ' ', $adj['adjustment_type'])) ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <?= format_currency_usd((float)$adj['amount_usd']) ?>
                                <?php if ((float)$adj['amount_lbp'] != 0): ?>
                                    <br><small class="text-muted"><?= format_currency_lbp((float)$adj['amount_lbp']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(substr($adj['reason'], 0, 30)) ?><?= strlen($adj['reason']) > 30 ? '...' : '' ?></td>
                            <td><?= htmlspecialchars($adj['performed_by_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function updateBalance(select) {
    const option = select.options[select.selectedIndex];
    const balanceDiv = document.getElementById('current-balance');
    const balanceUsd = document.getElementById('balance-usd');
    const balanceLbp = document.getElementById('balance-lbp');

    if (option.value) {
        const usd = parseFloat(option.dataset.balanceUsd || 0);
        const lbp = parseFloat(option.dataset.balanceLbp || 0);
        balanceUsd.textContent = '$' + usd.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        balanceLbp.textContent = 'LBP ' + lbp.toLocaleString('en-US', {maximumFractionDigits: 0});
        balanceDiv.style.display = 'block';
    } else {
        balanceDiv.style.display = 'none';
    }
}
</script>

<?php
accounting_render_layout_end();
