<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/van_loading_auth.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle OTP confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_otp') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $loadingId = trim((string)($_POST['loading_id'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));

        $errors = [];

        if ($loadingId === '') {
            $errors[] = 'Invalid loading request ID.';
        }

        if ($otp === '' || !preg_match('/^\d{6}$/', $otp)) {
            $errors[] = 'Please enter a valid 6-digit OTP code.';
        }

        if ($errors) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to confirm loading request.',
                'list' => $errors,
                'dismissible' => true,
            ];
        } else {
            $confirmed = confirm_loading_by_sales_rep($pdo, $loadingId, $otp);

            if ($confirmed) {
                // Check if loading is now complete
                $checkStmt = $pdo->prepare("SELECT completed_at FROM van_loading_requests WHERE loading_id = ?");
                $checkStmt->execute([$loadingId]);
                $result = $checkStmt->fetch();

                if ($result && $result['completed_at']) {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Loading Complete!',
                        'message' => 'Both parties confirmed. Stock has been loaded to your van.',
                        'dismissible' => true,
                    ];
                } else {
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'OTP Confirmed',
                        'message' => 'Your confirmation has been recorded. The loading will be processed once the warehouse confirms.',
                        'dismissible' => true,
                    ];
                }
            } else {
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Invalid OTP',
                    'message' => 'The OTP code you entered is invalid, expired, or has already been used.',
                    'dismissible' => true,
                ];
            }
        }
    }
}

// Get pending loadings for this sales rep
$pendingLoadings = get_pending_loadings_for_sales_rep($pdo, $repId);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Van Loading Authorization',
    'heading' => '🔐 Van Loading Authorization',
    'subtitle' => 'Authorize stock transfers to your van',
    'active' => 'stock_auth',
    'user' => $user,
    'extra_head' => '<style>
        .auth-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .pending-loadings {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .loading-card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 24px;
            border: 2px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .loading-card.pending-initiator {
            border-left: 4px solid #f59e0b;
        }
        .loading-card.pending-both {
            border-left: 4px solid #3b82f6;
        }
        .loading-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .loading-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
        }
        .loading-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-waiting-initiator {
            background: #fef3c7;
            color: #92400e;
        }
        .status-waiting-you {
            background: #dbeafe;
            color: #1e40af;
        }
        .loading-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
            padding: 16px;
            background: var(--bg-panel-alt);
            border-radius: 8px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .detail-label {
            font-size: 0.85rem;
            color: var(--muted);
            font-weight: 500;
        }
        .detail-value {
            font-size: 1rem;
            color: var(--text);
            font-weight: 600;
        }
        .otp-form {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            padding: 16px;
            background: #f0f9ff;
            border: 2px solid #3b82f6;
            border-radius: 10px;
        }
        .otp-input-group {
            flex: 1;
        }
        .otp-input-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 6px;
        }
        .otp-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #3b82f6;
            border-radius: 8px;
            font-size: 1.2rem;
            font-weight: 600;
            text-align: center;
            letter-spacing: 0.3em;
        }
        .btn-confirm-otp {
            padding: 12px 24px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-confirm-otp:hover {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }
        .waiting-message {
            padding: 16px;
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 10px;
            color: #92400e;
            text-align: center;
            font-weight: 500;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        .flash-stack {
            margin-bottom: 24px;
        }
        .flash {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 12px;
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
        .flash-title {
            font-weight: 700;
            margin-bottom: 6px;
            font-size: 1.1rem;
        }
        .flash-list {
            margin: 8px 0 0 20px;
            padding: 0;
        }
        .info-box {
            background: #eff6ff;
            border: 2px solid #3b82f6;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .info-box h3 {
            margin: 0 0 8px 0;
            color: #1e40af;
            font-size: 1.1rem;
        }
        .info-box p {
            margin: 0;
            color: #1e40af;
            line-height: 1.6;
        }
        .items-preview {
            margin-top: 12px;
            padding: 12px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        .items-preview-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }
        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .item-row:last-child {
            border-bottom: none;
        }
        .nav-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        .nav-tab {
            padding: 12px 20px;
            background: white;
            border: 2px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text);
            font-weight: 500;
            transition: all 0.2s;
        }
        .nav-tab:hover {
            border-color: var(--primary);
        }
        .nav-tab.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
    </style>',
]);

// Render flash messages
if ($flashes) {
    echo '<div class="flash-stack">';
    foreach ($flashes as $flash) {
        $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
        $title = isset($flash['title']) ? htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') : '';
        $message = isset($flash['message']) ? htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') : '';
        $list = $flash['list'] ?? [];

        echo '<div class="flash flash-', $type, '">';
        if ($title) {
            echo '<div class="flash-title">', $title, '</div>';
        }
        if ($message) {
            echo '<div>', $message, '</div>';
        }
        if ($list) {
            echo '<ul class="flash-list">';
            foreach ($list as $item) {
                echo '<li>', htmlspecialchars($item, ENT_QUOTES, 'UTF-8'), '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    echo '</div>';
}

echo '<div class="auth-container">';

// Navigation tabs
echo '<div class="nav-tabs">';
echo '<a href="stock_adjustment_auth.php" class="nav-tab">Stock Adjustments</a>';
echo '<a href="van_loading_auth.php" class="nav-tab active">Van Loading</a>';
echo '</div>';

// Info box
echo '<div class="info-box">';
echo '<h3>📋 How Van Loading Works</h3>';
echo '<p>When the warehouse loads stock onto your van, they will share an OTP code with you. ';
echo 'Enter that code below to confirm that you received the items. ';
echo 'Both parties must confirm before the stock is added to your van.</p>';
echo '</div>';

if (empty($pendingLoadings)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">✅</div>';
    echo '<h3>No Pending Van Loadings</h3>';
    echo '<p>You have no van loading requests awaiting your confirmation.</p>';
    echo '</div>';
} else {
    echo '<div class="pending-loadings">';

    foreach ($pendingLoadings as $loading) {
        $loadingId = htmlspecialchars($loading['loading_id'], ENT_QUOTES, 'UTF-8');
        $warehouseUserName = htmlspecialchars($loading['warehouse_user_name'] ?? 'Warehouse', ENT_QUOTES, 'UTF-8');
        $note = $loading['note'] ? htmlspecialchars($loading['note'], ENT_QUOTES, 'UTF-8') : null;
        $warehouseConfirmed = (bool)$loading['warehouse_confirmed'];
        $salesRepConfirmed = (bool)$loading['sales_rep_confirmed'];
        $expiresAt = $loading['expires_at'];
        $createdAt = $loading['created_at'];
        $itemCount = (int)$loading['item_count'];
        $totalQuantity = (float)$loading['total_quantity'];

        $cardClass = 'loading-card';
        $statusClass = '';
        $statusText = '';

        if ($salesRepConfirmed) {
            $cardClass .= ' pending-initiator';
            $statusClass = 'status-waiting-initiator';
            $statusText = '⏳ Waiting for Warehouse';
        } else {
            $cardClass .= ' pending-both';
            $statusClass = 'status-waiting-you';
            $statusText = '⏳ Waiting for Your Confirmation';
        }

        echo '<div class="', $cardClass, '">';
        echo '<div class="loading-header">';
        echo '<div class="loading-title">🚚 Van Loading from ', $warehouseUserName, '</div>';
        echo '<div class="loading-status ', $statusClass, '">', $statusText, '</div>';
        echo '</div>';

        echo '<div class="loading-details">';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">Products</span>';
        echo '<span class="detail-value">', $itemCount, ' items</span>';
        echo '</div>';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">Total Units</span>';
        echo '<span class="detail-value">', number_format($totalQuantity, 0), '</span>';
        echo '</div>';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">From</span>';
        echo '<span class="detail-value">', $warehouseUserName, '</span>';
        echo '</div>';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">Expires At</span>';
        echo '<span class="detail-value" style="font-size:0.9rem;">', date('M j, g:i A', strtotime($expiresAt)), '</span>';
        echo '</div>';
        echo '</div>';

        // Get loading items for preview
        $items = get_loading_items($pdo, $loading['loading_id']);
        if (!empty($items)) {
            echo '<div class="items-preview">';
            echo '<div class="items-preview-title">Items to receive:</div>';
            foreach (array_slice($items, 0, 5) as $item) {
                echo '<div class="item-row">';
                echo '<span>', htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'), '</span>';
                echo '<span style="font-weight:600;">', number_format((float)$item['quantity'], 0), ' ', htmlspecialchars($item['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8'), '</span>';
                echo '</div>';
            }
            if (count($items) > 5) {
                echo '<div style="text-align:center;padding:8px;color:var(--muted);font-size:0.85rem;">... and ', (count($items) - 5), ' more items</div>';
            }
            echo '</div>';
        }

        if ($note) {
            echo '<div style="padding:12px;background:var(--bg-panel-alt);border-radius:8px;margin-top:12px;">';
            echo '<strong style="color:var(--muted);font-size:0.85rem;">Note:</strong> ';
            echo '<span style="color:var(--text);">', $note, '</span>';
            echo '</div>';
        }

        if ($salesRepConfirmed) {
            echo '<div class="waiting-message" style="margin-top:16px;">';
            echo '✓ You have confirmed this loading. Waiting for the warehouse to confirm.';
            echo '</div>';
        } else {
            echo '<form method="POST" class="otp-form" style="margin-top:16px;">';
            echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
            echo '<input type="hidden" name="action" value="confirm_otp">';
            echo '<input type="hidden" name="loading_id" value="', $loadingId, '">';
            echo '<div class="otp-input-group">';
            echo '<label>🔐 Enter OTP Code from Warehouse</label>';
            echo '<input type="text" name="otp" class="otp-input" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">';
            echo '</div>';
            echo '<button type="submit" class="btn-confirm-otp">✅ Confirm Loading</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    echo '</div>';
}

echo '</div>';

sales_portal_render_layout_end();
