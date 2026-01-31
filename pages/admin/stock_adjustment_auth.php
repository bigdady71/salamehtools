<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/stock_adjustment_auth.php';

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
        $adjustmentId = trim((string)($_POST['adjustment_id'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));

        $errors = [];

        if ($adjustmentId === '') {
            $errors[] = 'Invalid adjustment ID.';
        }

        if ($otp === '' || !preg_match('/^\d{6}$/', $otp)) {
            $errors[] = 'Please enter a valid 6-digit OTP code.';
        }

        if ($errors) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to confirm adjustment.',
                'list' => $errors,
                'dismissible' => true,
            ];
        } else {
            $confirmed = confirm_adjustment_by_sales_rep($pdo, $adjustmentId, $otp);

            if ($confirmed) {
                $flashes[] = [
                    'type' => 'success',
                    'title' => 'OTP Confirmed',
                    'message' => 'Your confirmation has been recorded. The adjustment will be processed once both parties have confirmed.',
                    'dismissible' => true,
                ];
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

// Get pending adjustments for this sales rep
$pendingAdjustments = get_pending_adjustments_for_sales_rep($pdo, $repId);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'تصريح تعديل المخزون',
    'heading' => '🔐 تصريح تعديل المخزون',
    'subtitle' => 'مراجعة وتصريح تعديلات المخزون في سيارتك',
    'active' => 'stock_auth',
    'user' => $user,
    'extra_head' => '<style>
        .auth-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .pending-adjustments {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .adjustment-card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 24px;
            border: 2px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .adjustment-card.pending-initiator {
            border-left: 4px solid #f59e0b;
        }
        .adjustment-card.pending-both {
            border-left: 4px solid #3b82f6;
        }
        .adjustment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .adjustment-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
        }
        .adjustment-status {
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
        .adjustment-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .qty-increase {
            color: #059669;
        }
        .qty-decrease {
            color: #dc2626;
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
echo '<a href="stock_adjustment_auth.php" class="nav-tab active">تعديلات المخزون</a>';
echo '<a href="van_loading_auth.php" class="nav-tab">تحميل السيارة</a>';
echo '</div>';

// Info box
echo '<div class="info-box">';
echo '<h3>📋 كيف يعمل</h3>';
echo '<p>عندما يبدأ مدير أو مسؤول المستودع تعديل مخزون لسيارتك، سيتلقون رمز OTP. ';
echo 'يجب عليهم مشاركة هذا الرمز معك. أدخل الرمز أدناه لتصريح التعديل. ';
echo 'يجب على الطرفين التأكيد قبل تطبيق التعديل.</p>';
echo '</div>';

if (empty($pendingAdjustments)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">✅</div>';
    echo '<h3>لا توجد تصاريح معلقة</h3>';
    echo '<p>ليس لديك تعديلات مخزون تنتظر تصريحك.</p>';
    echo '</div>';
} else {
    echo '<div class="pending-adjustments">';

    foreach ($pendingAdjustments as $adj) {
        $adjustmentId = htmlspecialchars($adj['adjustment_id'], ENT_QUOTES, 'UTF-8');
        $productName = htmlspecialchars($adj['product_name'], ENT_QUOTES, 'UTF-8');
        $sku = htmlspecialchars($adj['sku'], ENT_QUOTES, 'UTF-8');
        $deltaQty = (float)$adj['delta_qty'];
        $reason = htmlspecialchars($adj['reason'], ENT_QUOTES, 'UTF-8');
        $note = $adj['note'] ? htmlspecialchars($adj['note'], ENT_QUOTES, 'UTF-8') : null;
        $initiatorName = htmlspecialchars($adj['initiator_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $initiatorType = htmlspecialchars($adj['initiator_type'], ENT_QUOTES, 'UTF-8');
        $initiatorConfirmed = (bool)$adj['initiator_confirmed'];
        $salesRepConfirmed = (bool)$adj['sales_rep_confirmed'];
        $expiresAt = $adj['expires_at'];
        $createdAt = $adj['created_at'];

        $cardClass = 'adjustment-card';
        $statusClass = '';
        $statusText = '';

        if ($salesRepConfirmed) {
            $cardClass .= ' pending-initiator';
            $statusClass = 'status-waiting-initiator';
            $statusText = '⏳ بانتظار المُبادِر';
        } else {
            $cardClass .= ' pending-both';
            $statusClass = 'status-waiting-you';
            $statusText = '⏳ بانتظار تأكيدك';
        }

        $qtyClass = $deltaQty > 0 ? 'qty-increase' : 'qty-decrease';
        $qtySign = $deltaQty > 0 ? '+' : '';

        echo '<div class="', $cardClass, '">';
        echo '<div class="adjustment-header">';
        echo '<div class="adjustment-title">📦 ', $productName, ' <span style="color:var(--muted);font-size:0.9rem;">(', $sku, ')</span></div>';
        echo '<div class="adjustment-status ', $statusClass, '">', $statusText, '</div>';
        echo '</div>';

        echo '<div class="adjustment-details">';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">تغيير الكمية</span>';
        echo '<span class="detail-value ', $qtyClass, '">', $qtySign, number_format($deltaQty, 1), ' وحدة</span>';
        echo '</div>';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">السبب</span>';
        echo '<span class="detail-value">', ucwords(str_replace('_', ' ', $reason)), '</span>';
        echo '</div>';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">بدأها</span>';
        echo '<span class="detail-value">', $initiatorName, '</span>';
        echo '</div>';
        echo '<div class="detail-item">';
        echo '<span class="detail-label">تنتهي في</span>';
        echo '<span class="detail-value" style="font-size:0.9rem;">', date('M j, g:i A', strtotime($expiresAt)), '</span>';
        echo '</div>';
        echo '</div>';

        if ($note) {
            echo '<div style="padding:12px;background:var(--bg-panel-alt);border-radius:8px;margin-bottom:16px;">';
            echo '<strong style="color:var(--muted);font-size:0.85rem;">ملاحظة:</strong> ';
            echo '<span style="color:var(--text);">', $note, '</span>';
            echo '</div>';
        }

        if ($salesRepConfirmed) {
            echo '<div class="waiting-message">';
            echo '✓ لقد قمت بتأكيد هذا التعديل. بانتظار المُبادِر لتأكيد رمز OTP الخاص به.';
            echo '</div>';
        } else {
            echo '<form method="POST" class="otp-form">';
            echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
            echo '<input type="hidden" name="action" value="confirm_otp">';
            echo '<input type="hidden" name="adjustment_id" value="', $adjustmentId, '">';
            echo '<div class="otp-input-group">';
            echo '<label>🔐 أدخل رمز OTP من المُبادِر</label>';
            echo '<input type="text" name="otp" class="otp-input" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">';
            echo '</div>';
            echo '<button type="submit" class="btn-confirm-otp">✅ تأكيد التعديل</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    echo '</div>';
}

echo '</div>';

sales_portal_render_layout_end();
