<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/van_loading_auth.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$loadingId = trim((string)($_GET['loading_id'] ?? ''));
$action = (string)($_POST['action'] ?? '');
$flashes = [];

if (!$loadingId) {
    header('Location: sales_reps_stocks.php');
    exit;
}

// Get loading request details
$stmt = $pdo->prepare("
    SELECT vlr.*,
           sr.name as sales_rep_name,
           sr.phone as sales_rep_phone
    FROM van_loading_requests vlr
    INNER JOIN users sr ON sr.id = vlr.sales_rep_id
    WHERE vlr.loading_id = :loading_id AND vlr.warehouse_user_id = :user_id
");
$stmt->execute([':loading_id' => $loadingId, ':user_id' => $user['id']]);
$loadingRequest = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loadingRequest) {
    header('Location: sales_reps_stocks.php');
    exit;
}

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
            $confirmed = confirm_loading_by_warehouse($pdo, $loadingId, $otp);

            if ($confirmed) {
                // Refresh the request data
                $stmt->execute([':loading_id' => $loadingId, ':user_id' => $user['id']]);
                $loadingRequest = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($loadingRequest['completed_at']) {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Loading Complete!',
                        'message' => 'Both parties have confirmed. Stock has been transferred to the van.',
                    ];
                } else {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'OTP Confirmed',
                        'message' => 'Your confirmation recorded. Waiting for sales rep to confirm.',
                    ];
                }
            } else {
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Invalid OTP',
                    'message' => 'The OTP code is invalid, expired, or already used.',
                ];
            }
        }
    }
}

// Get loading items
$items = get_loading_items($pdo, $loadingId);

$isExpired = strtotime($loadingRequest['expires_at']) < time();
$isCompleted = !is_null($loadingRequest['completed_at']);
$warehouseConfirmed = (bool)$loadingRequest['warehouse_confirmed'];
$salesRepConfirmed = (bool)$loadingRequest['sales_rep_confirmed'];

$csrfToken = csrf_token();

warehouse_portal_render_layout_start([
    'title' => 'Van Loading OTP',
    'heading' => 'Van Loading Authorization',
    'subtitle' => 'Two-factor authorization for stock transfer',
    'user' => $user,
    'active' => 'sales_reps_stocks',
]);
?>

<style>
    .otp-container {
        max-width: 700px;
        margin: 0 auto;
    }
    .status-card {
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        text-align: center;
    }
    .status-card.pending {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 2px solid #f59e0b;
    }
    .status-card.success {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        border: 2px solid #10b981;
    }
    .status-card.expired {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        border: 2px solid #ef4444;
    }
    .status-icon {
        font-size: 4rem;
        margin-bottom: 12px;
    }
    .status-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 8px;
    }
    .status-message {
        font-size: 1rem;
        opacity: 0.9;
    }
    .otp-display-section {
        background: white;
        border: 3px solid var(--primary);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
    }
    .otp-display-section h3 {
        margin: 0 0 16px;
        color: var(--primary);
        text-align: center;
    }
    .otp-boxes {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .otp-box {
        background: #f8fafc;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        text-align: center;
    }
    .otp-box.your-otp {
        background: #eff6ff;
        border-color: #3b82f6;
    }
    .otp-box.share-otp {
        background: #f0fdf4;
        border-color: #10b981;
    }
    .otp-box .label {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .otp-box.your-otp .label { color: #1e40af; }
    .otp-box.share-otp .label { color: #065f46; }
    .otp-box .code {
        font-size: 2rem;
        font-weight: 700;
        letter-spacing: 0.3em;
        font-family: monospace;
    }
    .otp-box.your-otp .code { color: #3b82f6; }
    .otp-box.share-otp .code { color: #10b981; }
    .otp-box .hint {
        font-size: 0.8rem;
        color: var(--muted);
        margin-top: 8px;
    }
    .confirm-section {
        background: #eff6ff;
        border: 2px solid #3b82f6;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
    }
    .confirm-section h3 {
        margin: 0 0 16px;
        color: #1e40af;
    }
    .confirm-form {
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
    .confirm-form button {
        padding: 14px 28px;
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .confirm-form button:hover {
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        transform: translateY(-1px);
    }
    .confirmed-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        background: #d1fae5;
        color: #065f46;
        border-radius: 8px;
        font-weight: 600;
    }
    .items-section {
        margin-bottom: 24px;
    }
    .items-section h3 {
        margin: 0 0 16px;
    }
    .item-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: white;
        border: 1px solid var(--border);
        border-radius: 8px;
        margin-bottom: 8px;
    }
    .item-row .item-image {
        width: 50px;
        height: 50px;
        object-fit: contain;
        background: #f9fafb;
        border-radius: 6px;
    }
    .item-row .item-image-placeholder {
        width: 50px;
        height: 50px;
        background: #f3f4f6;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: #9ca3af;
    }
    .item-row .item-info {
        flex: 1;
    }
    .item-row .item-name {
        font-weight: 600;
    }
    .item-row .item-sku {
        font-size: 0.85rem;
        color: var(--muted);
    }
    .item-row .item-qty {
        font-weight: 700;
        color: var(--primary);
        font-size: 1.1rem;
    }
    .rep-info {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px;
        background: #f0fdf4;
        border: 2px solid #10b981;
        border-radius: 10px;
        margin-bottom: 24px;
    }
    .rep-info .icon {
        font-size: 2rem;
    }
    .rep-info .name {
        font-weight: 600;
        font-size: 1.1rem;
    }
    .rep-info .phone {
        color: var(--muted);
    }
    .auth-status {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }
    .auth-box {
        padding: 16px;
        border-radius: 10px;
        text-align: center;
    }
    .auth-box.confirmed {
        background: #d1fae5;
        border: 2px solid #10b981;
    }
    .auth-box.pending {
        background: #fef3c7;
        border: 2px solid #f59e0b;
    }
    .auth-box .label {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .auth-box.confirmed .label { color: #065f46; }
    .auth-box.pending .label { color: #92400e; }
    .auth-box .status {
        font-size: 1.5rem;
    }
    .flash {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 16px;
        border: 2px solid;
    }
    .flash-success {
        background: #d1fae5;
        border-color: #065f46;
        color: #065f46;
    }
    .flash-error {
        background: #fee2e2;
        border-color: #991b1b;
        color: #991b1b;
    }
    .back-link {
        display: inline-block;
        margin-top: 16px;
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
    }
    .back-link:hover {
        text-decoration: underline;
    }
</style>

<div class="otp-container">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <strong><?= htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endforeach; ?>

    <?php if ($isCompleted): ?>
        <div class="status-card success">
            <div class="status-icon">✅</div>
            <div class="status-title">Loading Complete</div>
            <div class="status-message">
                Both parties have confirmed. Stock has been transferred to the van.
            </div>
        </div>
    <?php elseif ($isExpired): ?>
        <div class="status-card expired">
            <div class="status-icon">⏰</div>
            <div class="status-title">Request Expired</div>
            <div class="status-message">
                This loading request has expired. Please create a new one.
            </div>
        </div>
    <?php else: ?>
        <div class="status-card pending">
            <div class="status-icon">🔐</div>
            <div class="status-title">Authorization Required</div>
            <div class="status-message">
                Both you and the sales rep must enter OTP codes to complete this transfer.
                <br>
                Expires: <?= date('M j, Y g:i A', strtotime($loadingRequest['expires_at'])) ?>
            </div>
        </div>

        <div class="otp-display-section">
            <h3>OTP Codes</h3>
            <div class="otp-boxes">
                <div class="otp-box your-otp">
                    <div class="label">Your OTP Code</div>
                    <div class="code"><?= htmlspecialchars($loadingRequest['warehouse_otp'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="hint">Enter this below to confirm</div>
                </div>
                <div class="otp-box share-otp">
                    <div class="label">Sales Rep OTP Code</div>
                    <div class="code"><?= htmlspecialchars($loadingRequest['sales_rep_otp'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="hint">Share this with <?= htmlspecialchars($loadingRequest['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="rep-info">
        <div class="icon">🚚</div>
        <div>
            <div class="name"><?= htmlspecialchars($loadingRequest['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($loadingRequest['sales_rep_phone']): ?>
                <div class="phone"><?= htmlspecialchars($loadingRequest['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="auth-status">
        <div class="auth-box <?= $warehouseConfirmed ? 'confirmed' : 'pending' ?>">
            <div class="label">Warehouse</div>
            <div class="status"><?= $warehouseConfirmed ? '✓ Confirmed' : '⏳ Pending' ?></div>
        </div>
        <div class="auth-box <?= $salesRepConfirmed ? 'confirmed' : 'pending' ?>">
            <div class="label">Sales Rep</div>
            <div class="status"><?= $salesRepConfirmed ? '✓ Confirmed' : '⏳ Pending' ?></div>
        </div>
    </div>

    <?php if (!$isCompleted && !$isExpired && !$warehouseConfirmed): ?>
        <div class="confirm-section">
            <h3>Confirm Your Authorization</h3>
            <form method="POST" class="confirm-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="confirm_otp">
                <div class="input-group">
                    <label>Enter Your OTP Code</label>
                    <input type="text" name="otp" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">
                </div>
                <button type="submit">Confirm</button>
            </form>
        </div>
    <?php elseif (!$isCompleted && !$isExpired && $warehouseConfirmed): ?>
        <div class="confirm-section" style="background: #d1fae5; border-color: #10b981;">
            <div class="confirmed-badge">
                ✓ You have confirmed. Waiting for <?= htmlspecialchars($loadingRequest['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?> to confirm.
            </div>
        </div>
    <?php endif; ?>

    <div class="card items-section">
        <h3>Items to Load (<?= count($items) ?> products)</h3>
        <?php foreach ($items as $item): ?>
            <div class="item-row">
                <?php if (!empty($item['image_url'])): ?>
                    <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                         class="item-image">
                <?php else: ?>
                    <div class="item-image-placeholder">📦</div>
                <?php endif; ?>
                <div class="item-info">
                    <div class="item-name"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="item-sku"><?= htmlspecialchars($item['sku'] ?? 'No SKU', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="item-qty">
                    <?= number_format((float)$item['quantity'], 0) ?> <?= htmlspecialchars($item['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <a href="sales_reps_stocks.php" class="back-link">← Back to Sales Reps Stocks</a>
</div>

<?php
warehouse_portal_render_layout_end();
