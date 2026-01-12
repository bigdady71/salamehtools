<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/stock_return_auth.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$returnId = trim((string)($_GET['return_id'] ?? $_POST['return_id'] ?? ''));
$flashes = [];

// Handle OTP confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_otp') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
        ];
    } else {
        $otp = trim((string)($_POST['otp'] ?? ''));

        if (!preg_match('/^\d{6}$/', $otp)) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Invalid OTP',
                'message' => 'Please enter a valid 6-digit OTP code.',
            ];
        } else {
            $confirmed = confirm_return_by_warehouse($pdo, $returnId, $otp, (int)$user['id']);

            if ($confirmed) {
                // Check if return is now complete
                $checkStmt = $pdo->prepare("SELECT completed_at FROM stock_return_requests WHERE return_id = ?");
                $checkStmt->execute([$returnId]);
                $result = $checkStmt->fetch();

                if ($result && $result['completed_at']) {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Return Complete!',
                        'message' => 'Both parties confirmed. Stock has been returned to the warehouse inventory.',
                    ];
                } else {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'OTP Confirmed',
                        'message' => 'Your confirmation has been recorded. Waiting for sales rep confirmation.',
                    ];
                }
            } else {
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Invalid OTP',
                    'message' => 'The OTP code is invalid, expired, or has already been used.',
                ];
            }
        }
    }
}

// If viewing a specific return
$currentReturn = null;
$currentItems = [];
if ($returnId !== '') {
    $currentReturn = get_stock_return_request($pdo, $returnId);
    if ($currentReturn) {
        $currentItems = get_return_items($pdo, $returnId);
    }
}

// Get all pending returns
$pendingReturns = get_pending_returns_for_warehouse($pdo);

$csrfToken = csrf_token();

warehouse_portal_render_layout_start([
    'title' => 'Stock Returns',
    'heading' => 'Stock Returns',
    'subtitle' => 'Review and approve stock returns from sales reps',
    'user' => $user,
    'active' => 'stock_returns',
]);

// Render flash messages
foreach ($flashes as $flash) {
    echo '<div style="padding:16px 20px;border-radius:12px;margin-bottom:16px;border:2px solid;';
    if ($flash['type'] === 'success') {
        echo 'background:#d1fae5;border-color:#065f46;color:#065f46;';
    } else {
        echo 'background:#fee2e2;border-color:#991b1b;color:#991b1b;';
    }
    echo '">';
    echo '<strong>', htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8'), '</strong><br>';
    echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
}
?>

<style>
    .returns-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    .section-card {
        background: var(--bg-panel);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        border: 1px solid var(--border);
    }
    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin: 0 0 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .return-card {
        background: #f8fafc;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 16px;
        transition: all 0.2s;
    }
    .return-card:hover {
        border-color: var(--primary);
    }
    .return-card.active {
        border-color: var(--primary);
        background: #eff6ff;
    }
    .return-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
    }
    .return-rep-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .return-rep-avatar {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: white;
    }
    .return-rep-name {
        font-weight: 700;
        font-size: 1.1rem;
    }
    .return-rep-phone {
        font-size: 0.9rem;
        color: var(--muted);
    }
    .return-status {
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }
    .status-confirmed {
        background: #d1fae5;
        color: #065f46;
    }
    .return-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 16px;
    }
    .stat-box {
        background: white;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 12px;
        text-align: center;
    }
    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary);
    }
    .stat-label {
        font-size: 0.8rem;
        color: var(--muted);
        text-transform: uppercase;
    }
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 16px;
    }
    .items-table th,
    .items-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }
    .items-table th {
        font-weight: 600;
        color: var(--muted);
        font-size: 0.85rem;
        background: #f1f5f9;
    }
    .item-image {
        width: 50px;
        height: 50px;
        border-radius: 6px;
        object-fit: cover;
        background: #e5e7eb;
    }
    .item-image-placeholder {
        width: 50px;
        height: 50px;
        border-radius: 6px;
        background: #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: #9ca3af;
    }
    .otp-section {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 3px solid #f59e0b;
        border-radius: 16px;
        padding: 24px;
        margin-top: 20px;
    }
    .otp-section.completed {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        border-color: #10b981;
    }
    .otp-title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 16px;
        text-align: center;
    }
    .otp-codes {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 20px;
    }
    .otp-box {
        background: white;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 16px;
        text-align: center;
    }
    .otp-box.warehouse {
        border-color: #3b82f6;
    }
    .otp-box.sales-rep {
        border-color: #10b981;
    }
    .otp-label {
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .otp-box.warehouse .otp-label { color: #1e40af; }
    .otp-box.sales-rep .otp-label { color: #065f46; }
    .otp-code {
        font-size: 2rem;
        font-weight: 700;
        letter-spacing: 0.25em;
        font-family: monospace;
    }
    .otp-box.warehouse .otp-code { color: #3b82f6; }
    .otp-box.sales-rep .otp-code { color: #10b981; }
    .otp-hint {
        font-size: 0.8rem;
        color: var(--muted);
        margin-top: 6px;
    }
    .confirm-status {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 20px;
    }
    .confirm-box {
        padding: 16px;
        border-radius: 10px;
        text-align: center;
    }
    .confirm-box.confirmed {
        background: #d1fae5;
        border: 2px solid #10b981;
    }
    .confirm-box.pending {
        background: #fef3c7;
        border: 2px solid #f59e0b;
    }
    .confirm-box .label {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .confirm-box.confirmed .label { color: #065f46; }
    .confirm-box.pending .label { color: #92400e; }
    .confirm-box .status {
        font-size: 1.2rem;
    }
    .confirm-form {
        background: #eff6ff;
        border: 2px solid #3b82f6;
        border-radius: 12px;
        padding: 20px;
    }
    .confirm-form h4 {
        margin: 0 0 16px;
        color: #1e40af;
    }
    .confirm-form-row {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }
    .confirm-form .input-group {
        flex: 1;
    }
    .confirm-form label {
        display: block;
        font-weight: 600;
        color: #1e40af;
        margin-bottom: 8px;
    }
    .confirm-form input {
        width: 100%;
        padding: 14px;
        border: 2px solid #3b82f6;
        border-radius: 8px;
        font-size: 1.3rem;
        font-weight: 600;
        text-align: center;
        letter-spacing: 0.3em;
    }
    .btn {
        padding: 14px 28px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        font-size: 1rem;
    }
    .btn-primary {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
    }
    .btn-primary:hover {
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }
    .complete-banner {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        border: 3px solid #10b981;
        border-radius: 16px;
        padding: 32px;
        text-align: center;
        margin-top: 20px;
    }
    .complete-icon {
        font-size: 4rem;
        margin-bottom: 16px;
    }
    .complete-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #065f46;
        margin-bottom: 8px;
    }
    .complete-message {
        color: #065f46;
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--muted);
    }
    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 16px;
        opacity: 0.3;
    }
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--primary);
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 20px;
    }
    .back-link:hover {
        text-decoration: underline;
    }
</style>

<div class="returns-container">

<?php if ($currentReturn): ?>
    <!-- Single Return View -->
    <a href="stock_returns.php" class="back-link">← Back to All Returns</a>

    <div class="section-card">
        <div class="return-header">
            <div class="return-rep-info">
                <div class="return-rep-avatar">🚚</div>
                <div>
                    <div class="return-rep-name"><?= htmlspecialchars($currentReturn['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($currentReturn['sales_rep_phone']): ?>
                        <div class="return-rep-phone"><?= htmlspecialchars($currentReturn['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <span class="return-status <?= $currentReturn['status'] === 'completed' ? 'status-confirmed' : 'status-pending' ?>">
                <?= ucfirst($currentReturn['status']) ?>
            </span>
        </div>

        <?php if ($currentReturn['note']): ?>
            <div style="background: #f1f5f9; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
                <strong>Note:</strong> <?= htmlspecialchars($currentReturn['note'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="return-stats">
            <div class="stat-box">
                <div class="stat-value"><?= count($currentItems) ?></div>
                <div class="stat-label">Products</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= number_format(array_sum(array_column($currentItems, 'quantity')), 1) ?></div>
                <div class="stat-label">Total Units</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= date('M j', strtotime($currentReturn['created_at'])) ?></div>
                <div class="stat-label">Created</div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Quantity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($currentItems as $item): ?>
                    <tr>
                        <td>
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                     class="item-image">
                            <?php else: ?>
                                <div class="item-image-placeholder">📦</div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><strong><?= number_format((float)$item['quantity'], 1) ?></strong> <?= htmlspecialchars($item['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $isCompleted = $currentReturn['completed_at'] !== null;
        $warehouseConfirmed = (bool)$currentReturn['warehouse_confirmed'];
        $salesRepConfirmed = (bool)$currentReturn['sales_rep_confirmed'];
        ?>

        <?php if ($isCompleted): ?>
            <!-- Completed Banner -->
            <div class="complete-banner">
                <div class="complete-icon">✅</div>
                <div class="complete-title">Return Complete</div>
                <div class="complete-message">
                    Stock has been transferred back to warehouse inventory.<br>
                    Completed: <?= date('M j, Y g:i A', strtotime($currentReturn['completed_at'])) ?>
                </div>
            </div>
        <?php else: ?>
            <!-- OTP Section -->
            <div class="otp-section">
                <div class="otp-title">🔐 Two-Factor Authorization</div>

                <div class="otp-codes">
                    <div class="otp-box warehouse">
                        <div class="otp-label">Your OTP Code</div>
                        <div class="otp-code"><?= htmlspecialchars($currentReturn['warehouse_otp'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="otp-hint">Enter this below to confirm</div>
                    </div>
                    <div class="otp-box sales-rep">
                        <div class="otp-label">Sales Rep OTP Code</div>
                        <div class="otp-code"><?= htmlspecialchars($currentReturn['sales_rep_otp'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="otp-hint">Sales rep needs this code</div>
                    </div>
                </div>

                <div class="confirm-status">
                    <div class="confirm-box <?= $warehouseConfirmed ? 'confirmed' : 'pending' ?>">
                        <div class="label">Warehouse</div>
                        <div class="status"><?= $warehouseConfirmed ? '✓ Confirmed' : '⏳ Pending' ?></div>
                    </div>
                    <div class="confirm-box <?= $salesRepConfirmed ? 'confirmed' : 'pending' ?>">
                        <div class="label">Sales Rep</div>
                        <div class="status"><?= $salesRepConfirmed ? '✓ Confirmed' : '⏳ Pending' ?></div>
                    </div>
                </div>

                <?php if (!$warehouseConfirmed): ?>
                    <div class="confirm-form">
                        <h4>Confirm Your Authorization</h4>
                        <form method="POST" class="confirm-form-row">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="confirm_otp">
                            <input type="hidden" name="return_id" value="<?= htmlspecialchars($returnId, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="input-group">
                                <label>Enter Your OTP Code</label>
                                <input type="text" name="otp" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-primary">Confirm Receipt</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="background: #d1fae5; padding: 16px; border-radius: 8px; text-align: center; color: #065f46; font-weight: 600;">
                        ✓ You have confirmed. Waiting for <?= htmlspecialchars($currentReturn['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?> to confirm.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px; font-size: 0.85rem; color: var(--muted);">
            Created: <?= date('M j, Y g:i A', strtotime($currentReturn['created_at'])) ?> •
            Expires: <?= date('M j, Y g:i A', strtotime($currentReturn['expires_at'])) ?>
        </div>
    </div>

<?php else: ?>
    <!-- All Pending Returns List -->
    <div class="section-card">
        <h2 class="section-title">📦 Pending Stock Returns</h2>

        <?php if (empty($pendingReturns)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <h3>No Pending Returns</h3>
                <p>There are no stock return requests awaiting approval.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendingReturns as $return): ?>
                <div class="return-card">
                    <div class="return-header">
                        <div class="return-rep-info">
                            <div class="return-rep-avatar">🚚</div>
                            <div>
                                <div class="return-rep-name"><?= htmlspecialchars($return['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if ($return['sales_rep_phone']): ?>
                                    <div class="return-rep-phone"><?= htmlspecialchars($return['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span class="return-status <?= $return['warehouse_confirmed'] ? 'status-confirmed' : 'status-pending' ?>">
                                <?= $return['warehouse_confirmed'] ? 'You Confirmed' : 'Pending' ?>
                            </span>
                            <div style="font-size: 0.85rem; color: var(--muted); margin-top: 4px;">
                                <?= date('M j, g:i A', strtotime($return['created_at'])) ?>
                            </div>
                        </div>
                    </div>

                    <div class="return-stats">
                        <div class="stat-box">
                            <div class="stat-value"><?= (int)$return['item_count'] ?></div>
                            <div class="stat-label">Products</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?= number_format((float)$return['total_quantity'], 1) ?></div>
                            <div class="stat-label">Total Units</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?= date('g:i A', strtotime($return['expires_at'])) ?></div>
                            <div class="stat-label">Expires</div>
                        </div>
                    </div>

                    <?php if ($return['note']): ?>
                        <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 0.9rem;">
                            <strong>Note:</strong> <?= htmlspecialchars($return['note'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; gap: 12px;">
                            <span style="padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; <?= $return['sales_rep_confirmed'] ? 'background: #d1fae5; color: #065f46;' : 'background: #fef3c7; color: #92400e;' ?>">
                                Sales Rep: <?= $return['sales_rep_confirmed'] ? '✓ Confirmed' : 'Pending' ?>
                            </span>
                            <span style="padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; <?= $return['warehouse_confirmed'] ? 'background: #d1fae5; color: #065f46;' : 'background: #fef3c7; color: #92400e;' ?>">
                                Warehouse: <?= $return['warehouse_confirmed'] ? '✓ Confirmed' : 'Pending' ?>
                            </span>
                        </div>
                        <a href="?return_id=<?= htmlspecialchars($return['return_id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="btn btn-primary" style="padding: 10px 20px;">
                            View & Confirm
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>

<?php
warehouse_portal_render_layout_end();
