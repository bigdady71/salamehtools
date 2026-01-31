<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/stock_return_auth.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle creating new return request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_return') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
        ];
    } else {
        $note = trim((string)($_POST['note'] ?? ''));
        $items = [];

        // Collect items from form
        foreach ($_POST as $key => $value) {
            if (preg_match('/^qty_(\d+)$/', $key, $matches)) {
                $productId = (int)$matches[1];
                $quantity = (float)$value;
                if ($quantity > 0) {
                    $items[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ];
                }
            }
        }

        if (empty($items)) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'No Items Selected',
                'message' => 'Please select at least one item to return.',
            ];
        } else {
            try {
                $result = create_stock_return_request($pdo, $repId, $items, $note ?: null);

                $flashes[] = [
                    'type' => 'success',
                    'title' => 'Return Request Created',
                    'message' => 'Your stock return request has been submitted. Share the OTP code with warehouse personnel to complete the return.',
                ];
            } catch (Exception $e) {
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Error',
                    'message' => $e->getMessage(),
                ];
            }
        }
    }
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
        $returnId = trim((string)($_POST['return_id'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));

        if ($returnId === '' || !preg_match('/^\d{6}$/', $otp)) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Invalid OTP',
                'message' => 'Please enter a valid 6-digit OTP code.',
            ];
        } else {
            $confirmed = confirm_return_by_sales_rep($pdo, $returnId, $otp, $repId);

            if ($confirmed) {
                // Check if return is now complete
                $checkStmt = $pdo->prepare("SELECT completed_at FROM stock_return_requests WHERE return_id = ?");
                $checkStmt->execute([$returnId]);
                $result = $checkStmt->fetch();

                if ($result && $result['completed_at']) {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Return Complete!',
                        'message' => 'Both parties confirmed. Stock has been returned to the warehouse.',
                    ];
                } else {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'OTP Confirmed',
                        'message' => 'Your confirmation has been recorded. Waiting for warehouse confirmation.',
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

// Handle cancel return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_return') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token.',
        ];
    } else {
        $returnId = trim((string)($_POST['return_id'] ?? ''));
        if ($returnId !== '') {
            $cancelled = cancel_stock_return($pdo, $returnId, $repId, 'Cancelled by sales rep');
            if ($cancelled) {
                $flashes[] = [
                    'type' => 'success',
                    'title' => 'Cancelled',
                    'message' => 'Stock return request has been cancelled.',
                ];
            }
        }
    }
}

// Get van stock for return selection
$stockStmt = $pdo->prepare("
    SELECT
        s.product_id,
        s.qty_on_hand,
        p.sku,
        p.item_name,
        p.unit,
        p.image_url
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id AND s.qty_on_hand > 0
    ORDER BY p.item_name
");
$stockStmt->execute([':rep_id' => $repId]);
$vanStock = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending returns
$pendingReturns = get_pending_returns_for_sales_rep($pdo, $repId);

// Get return history
$returnHistory = get_return_history_for_sales_rep($pdo, $repId, 10);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'إرجاع المخزون',
    'heading' => '📦 إرجاع المخزون للمستودع',
    'subtitle' => 'إرجاع العناصر من مخزون سيارتك إلى المستودع',
    'active' => 'stock_return',
    'user' => $user,
    'extra_head' => '<style>
        .return-container {
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
        .stock-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        .stock-item {
            background: #f8fafc;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s;
        }
        .stock-item:hover {
            border-color: var(--primary);
        }
        .stock-item.selected {
            border-color: var(--primary);
            background: #eff6ff;
        }
        .stock-item-header {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
        }
        .stock-item-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            background: #e5e7eb;
        }
        .stock-item-image-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #9ca3af;
        }
        .stock-item-info {
            flex: 1;
        }
        .stock-item-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .stock-item-sku {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .stock-item-available {
            font-size: 0.9rem;
            color: #059669;
            font-weight: 600;
        }
        .qty-input-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }
        .qty-input-group label {
            font-size: 0.9rem;
            font-weight: 600;
        }
        .qty-input {
            width: 100px;
            padding: 8px 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
        }
        .qty-input:focus {
            border-color: var(--primary);
            outline: none;
        }
        .pending-return {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .pending-return.confirmed {
            background: #d1fae5;
            border-color: #10b981;
        }
        .pending-return-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .pending-return-title {
            font-weight: 700;
            font-size: 1.1rem;
        }
        .pending-return-status {
            padding: 4px 12px;
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
        .otp-display {
            background: white;
            border: 3px solid var(--primary);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            margin: 16px 0;
        }
        .otp-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }
        .otp-code {
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: 0.3em;
            font-family: monospace;
            color: #1e40af;
        }
        .otp-hint {
            font-size: 0.85rem;
            color: var(--muted);
            margin-top: 8px;
        }
        .confirm-section {
            background: #eff6ff;
            border: 2px solid #3b82f6;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        .confirm-section h4 {
            margin: 0 0 12px;
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
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .confirm-form input {
            width: 100%;
            padding: 12px;
            border: 2px solid #3b82f6;
            border-radius: 8px;
            font-size: 1.2rem;
            font-weight: 600;
            text-align: center;
            letter-spacing: 0.2em;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }
        .history-table th,
        .history-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .history-table th {
            font-weight: 600;
            color: var(--muted);
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-completed {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-cancelled {
            background: #fee2e2;
            color: #991b1b;
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
        .info-box {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.1) 0%, rgba(6, 182, 212, 0.1) 100%);
            border: 1px solid rgba(14, 165, 233, 0.3);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .info-box-icon {
            font-size: 1.5rem;
        }
        .info-box-text {
            flex: 1;
        }
        .info-box-text strong {
            display: block;
            margin-bottom: 4px;
        }
        .submit-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid var(--border);
        }
        .note-input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 16px;
            resize: vertical;
            min-height: 80px;
        }
        .items-list {
            margin-top: 12px;
        }
        .items-list-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }
    </style>',
]);

// Render flash messages
foreach ($flashes as $flash) {
    echo '<div class="flash flash-', htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'), '">';
    echo '<strong>', htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8'), '</strong><br>';
    echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
}
?>

<div class="return-container">
    <!-- Info Box -->
    <div class="info-box">
        <div class="info-box-icon">📋</div>
        <div class="info-box-text">
            <strong>كيف يعمل إرجاع المخزون</strong>
            هذه عملية نقل داخلية لإرجاع المنتجات من سيارتك إلى المستودع.
            يجب عليك أنت والمستودع التأكيد برموز OTP لإتمام العملية.
            هذا لا يؤثر على المبيعات ولا ينشئ مرتجعات.
        </div>
    </div>

    <?php if (!empty($pendingReturns)): ?>
        <!-- Pending Returns Section -->
        <div class="section-card">
            <h2 class="section-title">⏳ طلبات الإرجاع المعلقة</h2>

            <?php foreach ($pendingReturns as $pending):
                $request = get_stock_return_request($pdo, $pending['return_id']);
                $items = get_return_items($pdo, $pending['return_id']);
                $isConfirmed = (bool)$pending['sales_rep_confirmed'];
                $warehouseConfirmed = (bool)$pending['warehouse_confirmed'];
            ?>
                <div class="pending-return <?= $isConfirmed ? 'confirmed' : '' ?>">
                    <div class="pending-return-header">
                        <div class="pending-return-title">
                            Return Request - <?= (int)$pending['item_count'] ?> items, <?= number_format((float)$pending['total_quantity'], 1) ?> units
                        </div>
                        <span class="pending-return-status <?= $isConfirmed ? 'status-confirmed' : 'status-pending' ?>">
                            <?= $isConfirmed ? '✓ You Confirmed' : 'Awaiting Your Confirmation' ?>
                        </span>
                    </div>

                    <?php if ($pending['note']): ?>
                        <p style="margin: 8px 0; color: var(--muted);">
                            <strong>Note:</strong> <?= htmlspecialchars($pending['note'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>

                    <div class="items-list">
                        <?php foreach ($items as $item): ?>
                            <div class="items-list-item">
                                <span><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                <span><strong><?= number_format((float)$item['quantity'], 1) ?></strong> <?= htmlspecialchars($item['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- OTP Display -->
                    <div class="otp-display">
                        <div class="otp-label">Your OTP Code (Share with Warehouse)</div>
                        <div class="otp-code"><?= htmlspecialchars($request['sales_rep_otp'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="otp-hint">The warehouse will need this code to confirm receipt</div>
                    </div>

                    <!-- Confirmation Status -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px;">
                        <div style="padding: 12px; border-radius: 8px; text-align: center; <?= $isConfirmed ? 'background: #d1fae5; border: 2px solid #10b981;' : 'background: #fef3c7; border: 2px solid #f59e0b;' ?>">
                            <div style="font-weight: 600;"><?= $isConfirmed ? '✓ You Confirmed' : '⏳ Your Confirmation' ?></div>
                        </div>
                        <div style="padding: 12px; border-radius: 8px; text-align: center; <?= $warehouseConfirmed ? 'background: #d1fae5; border: 2px solid #10b981;' : 'background: #fef3c7; border: 2px solid #f59e0b;' ?>">
                            <div style="font-weight: 600;"><?= $warehouseConfirmed ? '✓ Warehouse Confirmed' : '⏳ Warehouse Pending' ?></div>
                        </div>
                    </div>

                    <?php if (!$isConfirmed): ?>
                        <!-- Confirm Section -->
                        <div class="confirm-section">
                            <h4>Enter Warehouse OTP to Confirm</h4>
                            <form method="POST" class="confirm-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="confirm_otp">
                                <input type="hidden" name="return_id" value="<?= htmlspecialchars($pending['return_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="input-group">
                                    <label>Warehouse OTP Code</label>
                                    <input type="text" name="otp" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">
                                </div>
                                <button type="submit" class="btn btn-primary">Confirm</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- Cancel Button -->
                    <?php if (!$isConfirmed && !$warehouseConfirmed): ?>
                        <div class="action-buttons">
                            <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this return request?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="cancel_return">
                                <input type="hidden" name="return_id" value="<?= htmlspecialchars($pending['return_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-danger">Cancel Request</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 12px; font-size: 0.85rem; color: var(--muted);">
                        Created: <?= date('M j, Y g:i A', strtotime($pending['created_at'])) ?> •
                        Expires: <?= date('M j, Y g:i A', strtotime($pending['expires_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Create New Return Section -->
    <div class="section-card">
        <h2 class="section-title">📦 إنشاء طلب إرجاع مخزون</h2>

        <?php if (empty($vanStock)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                ليس لديك منتجات في مخزون السيارة للإرجاع.
            </p>
        <?php else: ?>
            <!-- Filter and Search Bar -->
            <div class="filter-bar" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                <input type="text" id="searchFilter" placeholder="بحث بالاسم أو الكود..."
                       oninput="filterStockItems()"
                       style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; font-size: 1rem; min-width: 200px;">
                <select id="categoryFilter" onchange="filterStockItems()"
                        style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; font-size: 1rem; min-width: 150px;">
                    <option value="">جميع الفئات</option>
                    <?php
                    // Get unique categories from van stock
                    $stockCategories = [];
                    foreach ($vanStock as $item) {
                        $catStmt = $pdo->prepare("SELECT topcat_name FROM products WHERE id = ?");
                        $catStmt->execute([$item['product_id']]);
                        $cat = $catStmt->fetchColumn();
                        if ($cat && !in_array($cat, $stockCategories)) {
                            $stockCategories[] = $cat;
                        }
                    }
                    sort($stockCategories);
                    foreach ($stockCategories as $cat):
                    ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="clearFilters()" style="padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; background: #f1f5f9; cursor: pointer;">
                    مسح الفلاتر
                </button>
            </div>

            <form method="POST" id="returnForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_return">

                <div class="stock-grid">
                    <?php foreach ($vanStock as $item):
                        // Get category for this item
                        $catStmt = $pdo->prepare("SELECT topcat_name FROM products WHERE id = ?");
                        $catStmt->execute([$item['product_id']]);
                        $itemCategory = $catStmt->fetchColumn() ?: '';
                    ?>
                        <div class="stock-item" id="item-<?= $item['product_id'] ?>"
                             data-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                             data-sku="<?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>"
                             data-category="<?= htmlspecialchars($itemCategory, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="stock-item-header">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                         class="stock-item-image">
                                <?php else: ?>
                                    <div class="stock-item-image-placeholder">📦</div>
                                <?php endif; ?>
                                <div class="stock-item-info">
                                    <div class="stock-item-name"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="stock-item-sku"><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                            <div class="stock-item-available">
                                المتوفر: <?= number_format((float)$item['qty_on_hand'], 1) ?> <?= htmlspecialchars($item['unit'] ?? 'قطعة', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="qty-input-group">
                                <label>كمية الإرجاع:</label>
                                <input type="number"
                                       name="qty_<?= $item['product_id'] ?>"
                                       class="qty-input"
                                       value="0"
                                       min="0"
                                       max="<?= (float)$item['qty_on_hand'] ?>"
                                       step="0.1"
                                       onchange="updateItemSelection(<?= $item['product_id'] ?>, this.value)">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="submit-section">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">ملاحظات (اختياري)</label>
                    <textarea name="note" class="note-input" placeholder="أضف ملاحظات حول هذا الإرجاع..."></textarea>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div id="selectedSummary" style="font-size: 0.9rem; color: var(--muted);">
                            لم يتم اختيار منتجات
                        </div>
                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                            إنشاء طلب الإرجاع
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($returnHistory)): ?>
        <!-- Return History Section -->
        <div class="section-card">
            <h2 class="section-title">📜 سجل الإرجاعات</h2>

            <table class="history-table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>المنتجات</th>
                        <th>الوحدات</th>
                        <th>الحالة</th>
                        <th>اكتمل في</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($returnHistory as $history): ?>
                        <tr>
                            <td><?= date('Y/m/d', strtotime($history['created_at'])) ?></td>
                            <td><?= (int)$history['item_count'] ?> منتج</td>
                            <td><?= number_format((float)$history['total_quantity'], 1) ?></td>
                            <td>
                                <span class="badge badge-<?= $history['status'] ?>">
                                    <?php
                                    $statusLabels = [
                                        'completed' => 'مكتمل',
                                        'pending' => 'معلق',
                                        'cancelled' => 'ملغى'
                                    ];
                                    echo $statusLabels[$history['status']] ?? $history['status'];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?= $history['completed_at'] ? date('Y/m/d H:i', strtotime($history['completed_at'])) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
let selectedItems = {};

// Filter stock items
function filterStockItems() {
    const search = document.getElementById('searchFilter').value.toLowerCase().trim();
    const category = document.getElementById('categoryFilter').value;

    document.querySelectorAll('.stock-item').forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        const sku = (item.dataset.sku || '').toLowerCase();
        const itemCategory = item.dataset.category || '';

        const matchesSearch = !search || name.includes(search) || sku.includes(search);
        const matchesCategory = !category || itemCategory === category;

        item.style.display = (matchesSearch && matchesCategory) ? 'block' : 'none';
    });
}

function clearFilters() {
    document.getElementById('searchFilter').value = '';
    document.getElementById('categoryFilter').value = '';
    filterStockItems();
}

function updateItemSelection(productId, qty) {
    const quantity = parseFloat(qty) || 0;
    const itemEl = document.getElementById('item-' + productId);

    if (quantity > 0) {
        selectedItems[productId] = quantity;
        itemEl.classList.add('selected');
    } else {
        delete selectedItems[productId];
        itemEl.classList.remove('selected');
    }

    updateSummary();
}

function updateSummary() {
    const count = Object.keys(selectedItems).length;
    const totalQty = Object.values(selectedItems).reduce((a, b) => a + b, 0);

    const summaryEl = document.getElementById('selectedSummary');
    const submitBtn = document.getElementById('submitBtn');

    if (count === 0) {
        summaryEl.textContent = 'لم يتم اختيار منتجات';
        submitBtn.disabled = true;
    } else {
        summaryEl.textContent = `${count} منتج، ${totalQty.toFixed(1)} وحدة مختارة`;
        submitBtn.disabled = false;
    }
}

// Form validation
document.getElementById('returnForm')?.addEventListener('submit', function(e) {
    if (Object.keys(selectedItems).length === 0) {
        e.preventDefault();
        alert('يرجى اختيار منتج واحد على الأقل للإرجاع.');
        return false;
    }

    return confirm('هل أنت متأكد من إنشاء طلب الإرجاع هذا؟');
});
</script>

<?php
sales_portal_render_layout_end();
