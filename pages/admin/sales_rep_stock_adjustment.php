<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/stock_adjustment_auth.php';
require_once __DIR__ . '/../../src/Middleware/RBACMiddleware.php';

use SalamehTools\Middleware\RBACMiddleware;

require_login();
$user = auth_user();
RBACMiddleware::requireAnyRole(['admin', 'warehouse_manager'], 'Access denied. Administrators and warehouse managers only.');

$userId = (int)$user['id'];
$userRole = (string)$user['role'];
$initiatorType = $userRole === 'admin' ? 'admin' : 'warehouse_manager';

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle new adjustment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_adjustment') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $salesRepId = (int)($_POST['sales_rep_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $deltaQty = (float)($_POST['delta_qty'] ?? 0);
        $reason = (string)($_POST['reason'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));

        $errors = [];

        if ($salesRepId <= 0) {
            $errors[] = 'Please select a sales representative.';
        }

        if ($productId <= 0) {
            $errors[] = 'Please select a product.';
        }

        if ($deltaQty == 0) {
            $errors[] = 'Quantity change cannot be zero.';
        }

        if (!in_array($reason, ['load', 'return', 'adjustment', 'transfer_in', 'transfer_out'])) {
            $errors[] = 'Please select a valid reason.';
        }

        if ($errors) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to create adjustment request.',
                'list' => $errors,
                'dismissible' => true,
            ];
        } else {
            try {
                $result = create_stock_adjustment_request(
                    $pdo,
                    $userId,
                    $initiatorType,
                    $salesRepId,
                    $productId,
                    $deltaQty,
                    $reason,
                    $note !== '' ? $note : null
                );

                $flashes[] = [
                    'type' => 'success',
                    'title' => 'Adjustment Request Created',
                    'message' => 'Stock adjustment request has been created successfully.',
                    'lines' => [
                        'YOUR OTP CODE: ' . $result['initiator_otp'],
                        'Share this code with the sales rep: ' . $result['sales_rep_otp'],
                        'Both parties must confirm within 15 minutes.',
                    ],
                    'dismissible' => false,
                ];
            } catch (Exception $e) {
                error_log("Failed to create adjustment request: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'System Error',
                    'message' => 'Unable to create adjustment request. Please try again.',
                    'dismissible' => true,
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
            $confirmed = confirm_adjustment_by_initiator($pdo, $adjustmentId, $otp);

            if ($confirmed) {
                $flashes[] = [
                    'type' => 'success',
                    'title' => 'OTP Confirmed',
                    'message' => 'Your confirmation has been recorded. The adjustment will be processed once the sales rep confirms.',
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

// Get all sales reps
$salesRepsStmt = $pdo->prepare("
    SELECT id, name, email
    FROM users
    WHERE role = 'sales_rep' AND is_active = 1
    ORDER BY name
");
$salesRepsStmt->execute();
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all products
$productsStmt = $pdo->prepare("
    SELECT id, sku, item_name, topcat as category
    FROM products
    WHERE is_active = 1
    ORDER BY item_name
");
$productsStmt->execute();
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending adjustments
$pendingAdjustments = get_pending_adjustments_for_initiator($pdo, $userId, $initiatorType);

// Overall pending adjustment stats
$pendingStats = $pdo->query("
    SELECT
        COUNT(*) as total_pending,
        SUM(CASE WHEN initiator_confirmed = 0 THEN 1 ELSE 0 END) as waiting_initiator,
        SUM(CASE WHEN sales_rep_confirmed = 0 THEN 1 ELSE 0 END) as waiting_sales_rep
    FROM stock_adjustment_otps
    WHERE completed_at IS NULL
      AND expires_at > NOW()
")->fetch(PDO::FETCH_ASSOC);

// Recent completed adjustments (audit trail)
$recentAdjustments = $pdo->query("
    SELECT
        oa.adjustment_id,
        oa.delta_qty,
        oa.reason,
        oa.note,
        oa.completed_at,
        oa.initiator_type,
        sr.name as sales_rep_name,
        p.item_name as product_name,
        p.sku,
        u.name as initiator_name
    FROM stock_adjustment_otps oa
    INNER JOIN products p ON p.id = oa.product_id
    INNER JOIN users sr ON sr.id = oa.sales_rep_id
    LEFT JOIN users u ON u.id = oa.initiator_id
    WHERE oa.completed_at IS NOT NULL
    ORDER BY oa.completed_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Sales rep stock overview
$repStockOverview = $pdo->query("
    SELECT
        s.salesperson_id as rep_id,
        u.name as rep_name,
        u.email as rep_email,
        COUNT(*) as sku_count,
        SUM(s.qty_on_hand) as total_units,
        SUM(s.qty_on_hand * p.wholesale_price_usd) as total_value,
        SUM(CASE WHEN s.qty_on_hand <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN s.qty_on_hand > 0 AND s.qty_on_hand <= 5 THEN 1 ELSE 0 END) as low_stock
    FROM s_stock s
    INNER JOIN users u ON u.id = s.salesperson_id
    INNER JOIN products p ON p.id = s.product_id
    GROUP BY s.salesperson_id, u.name, u.email
    ORDER BY total_value DESC
")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

admin_render_layout_start([
    'title' => 'Sales Rep Stock Adjustments',
    'heading' => '🔐 Sales Rep Stock Adjustments',
    'subtitle' => 'Manage stock adjustments for sales representatives with two-factor authentication',
    'active' => 'stock_adjustments',
    'user' => $user,
    'extra_head' => '<style>
        .page-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 900px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        .card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .card h2 {
            margin: 0 0 20px 0;
            font-size: 1.3rem;
            color: var(--text);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
        }
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .btn-primary {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }
        .pending-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .adjustment-item {
            padding: 16px;
            background: var(--bg-panel-alt);
            border-radius: 10px;
            border: 2px solid var(--border);
        }
        .adjustment-item.waiting-rep {
            border-left: 4px solid #f59e0b;
        }
        .adjustment-item.waiting-you {
            border-left: 4px solid #3b82f6;
        }
        .adjustment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .adjustment-title {
            font-weight: 600;
            color: var(--text);
        }
        .adjustment-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-waiting-rep {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-waiting-you {
            background: #dbeafe;
            color: #1e40af;
        }
        .adjustment-details {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.6;
        }
        .qty-change {
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
            gap: 8px;
            margin-top: 12px;
        }
        .otp-input {
            flex: 1;
            padding: 8px;
            border: 2px solid #3b82f6;
            border-radius: 6px;
            text-align: center;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 0.2em;
        }
        .btn-confirm {
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-confirm:hover {
            background: #2563eb;
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
        .flash-lines {
            background: rgba(0,0,0,0.05);
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .flash-lines div {
            margin: 6px 0;
        }
        .flash-list {
            margin: 8px 0 0 20px;
            padding: 0;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 18px;
            border: 1px solid var(--border);
            display: flex;
            gap: 12px;
            align-items: center;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--bg-panel-alt);
            border: 1px solid var(--border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .data-table th {
            background: var(--bg-panel-alt);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .data-table td {
            padding: 12px;
            border-top: 1px solid var(--border);
        }
        .data-table tr:nth-child(2n) {
            background: rgba(148, 163, 184, 0.06);
        }
        .status-pill {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-ok { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .status-warn { background: rgba(245, 158, 11, 0.15); color: #b45309; }
        .status-danger { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
    </style>',
]);

// Render flash messages
if ($flashes) {
    echo '<div class="flash-stack">';
    foreach ($flashes as $flash) {
        $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
        $title = isset($flash['title']) ? htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') : '';
        $message = isset($flash['message']) ? htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') : '';
        $lines = $flash['lines'] ?? [];
        $list = $flash['list'] ?? [];

        echo '<div class="flash flash-', $type, '">';
        if ($title) {
            echo '<div class="flash-title">', $title, '</div>';
        }
        if ($message) {
            echo '<div>', $message, '</div>';
        }
        if ($lines) {
            echo '<div class="flash-lines">';
            foreach ($lines as $line) {
                echo '<div>', htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), '</div>';
            }
            echo '</div>';
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

echo '<div class="page-container">';

echo '<div class="stats-grid">';
echo '<div class="stat-card"><div class="stat-icon">⏳</div><div><div class="stat-label">Pending Requests</div><div class="stat-value">' . number_format((int)($pendingStats['total_pending'] ?? 0)) . '</div></div></div>';
echo '<div class="stat-card"><div class="stat-icon">🛡️</div><div><div class="stat-label">Waiting Initiator</div><div class="stat-value">' . number_format((int)($pendingStats['waiting_initiator'] ?? 0)) . '</div></div></div>';
echo '<div class="stat-card"><div class="stat-icon">🤝</div><div><div class="stat-label">Waiting Sales Rep</div><div class="stat-value">' . number_format((int)($pendingStats['waiting_sales_rep'] ?? 0)) . '</div></div></div>';
echo '</div>';

echo '<div class="card" style="margin-bottom:24px;">';
echo '<h2>✅ How the Adjustment Flow Works</h2>';
echo '<ol style="margin:0 0 0 18px; color:var(--muted); line-height:1.7;">';
echo '<li>Admin/Warehouse creates an adjustment request (adds or removes van stock).</li>';
echo '<li>System generates two OTP codes: one for the initiator, one for the sales rep.</li>';
echo '<li>Both parties confirm. Once both are confirmed, the stock is updated and logged.</li>';
echo '</ol>';
echo '</div>';

echo '<div class="card" style="margin-bottom:24px;">';
echo '<h2>📊 Sales Rep Stock Overview</h2>';
if (empty($repStockOverview)) {
    echo '<div class="empty-state">No van stock data available.</div>';
} else {
    echo '<table class="data-table">';
    echo '<thead><tr><th>Sales Rep</th><th>SKUs</th><th>Units</th><th>Value</th><th>Alerts</th><th>Action</th></tr></thead>';
    echo '<tbody>';
    foreach ($repStockOverview as $summary) {
        $out = (int)$summary['out_of_stock'];
        $low = (int)$summary['low_stock'];
        $badgeClass = $out > 0 ? 'status-danger' : ($low > 0 ? 'status-warn' : 'status-ok');
        $badgeText = $out > 0 ? "{$out} out" : ($low > 0 ? "{$low} low" : 'OK');
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($summary['rep_name'], ENT_QUOTES, 'UTF-8') . '</strong><br><span style="color:var(--muted);font-size:0.85rem;">' . htmlspecialchars($summary['rep_email'] ?? '', ENT_QUOTES, 'UTF-8') . '</span></td>';
        echo '<td>' . number_format((int)$summary['sku_count']) . '</td>';
        echo '<td>' . number_format((float)$summary['total_units'], 1) . '</td>';
        echo '<td>$' . number_format((float)$summary['total_value'], 2) . '</td>';
        echo '<td><span class="status-pill ' . $badgeClass . '">' . $badgeText . '</span></td>';
        echo '<td><a class="btn-confirm" style="text-decoration:none;" href="van_stock_overview.php?rep_id=' . (int)$summary['rep_id'] . '">View Stock</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

echo '<div class="content-grid">';

// Create New Adjustment Form
echo '<div class="card">';
echo '<h2>🆕 Create New Adjustment</h2>';
echo '<form method="POST">';
echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
echo '<input type="hidden" name="action" value="create_adjustment">';

echo '<div class="form-group">';
echo '<label>Sales Representative <span style="color:red;">*</span></label>';
echo '<select name="sales_rep_id" required>';
echo '<option value="">Select sales rep...</option>';
foreach ($salesReps as $rep) {
    $repId = (int)$rep['id'];
    $repName = htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8');
    $repEmail = htmlspecialchars($rep['email'], ENT_QUOTES, 'UTF-8');
    echo '<option value="', $repId, '">', $repName, ' (', $repEmail, ')</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Product <span style="color:red;">*</span></label>';
echo '<select name="product_id" required>';
echo '<option value="">Select product...</option>';
foreach ($products as $product) {
    $prodId = (int)$product['id'];
    $prodSku = htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8');
    $prodName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
    $prodCat = $product['category'] ? ' [' . htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') . ']' : '';
    echo '<option value="', $prodId, '">', $prodSku, ' - ', $prodName, $prodCat, '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Quantity Change <span style="color:red;">*</span></label>';
echo '<input type="number" name="delta_qty" step="0.1" required placeholder="Use negative for decrease">';
echo '<small style="color:var(--muted);font-size:0.85rem;">Positive = add stock, Negative = remove stock</small>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Reason <span style="color:red;">*</span></label>';
echo '<select name="reason" required>';
echo '<option value="">Select reason...</option>';
echo '<option value="load">Load from Warehouse</option>';
echo '<option value="return">Return to Warehouse</option>';
echo '<option value="adjustment">Manual Adjustment</option>';
echo '<option value="transfer_in">Transfer In</option>';
echo '<option value="transfer_out">Transfer Out</option>';
echo '</select>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Notes</label>';
echo '<textarea name="note" placeholder="Optional notes about this adjustment..."></textarea>';
echo '</div>';

echo '<button type="submit" class="btn-primary">🚀 Create Adjustment Request</button>';
echo '</form>';
echo '</div>';

// Pending Adjustments
echo '<div class="card">';
echo '<h2>⏳ Pending Confirmations</h2>';

if (empty($pendingAdjustments)) {
    echo '<div class="empty-state">';
    echo '<p>No pending adjustments</p>';
    echo '</div>';
} else {
    echo '<div class="pending-list">';

    foreach ($pendingAdjustments as $adj) {
        $adjustmentId = htmlspecialchars($adj['adjustment_id'], ENT_QUOTES, 'UTF-8');
        $salesRepName = htmlspecialchars($adj['sales_rep_name'], ENT_QUOTES, 'UTF-8');
        $productName = htmlspecialchars($adj['product_name'], ENT_QUOTES, 'UTF-8');
        $sku = htmlspecialchars($adj['sku'], ENT_QUOTES, 'UTF-8');
        $deltaQty = (float)$adj['delta_qty'];
        $reason = htmlspecialchars($adj['reason'], ENT_QUOTES, 'UTF-8');
        $initiatorConfirmed = (bool)$adj['initiator_confirmed'];
        $salesRepConfirmed = (bool)$adj['sales_rep_confirmed'];

        $itemClass = 'adjustment-item';
        $badgeClass = '';
        $badgeText = '';

        if ($initiatorConfirmed) {
            $itemClass .= ' waiting-rep';
            $badgeClass = 'badge-waiting-rep';
            $badgeText = 'Waiting for Sales Rep';
        } else {
            $itemClass .= ' waiting-you';
            $badgeClass = 'badge-waiting-you';
            $badgeText = 'Waiting for You';
        }

        $qtyClass = $deltaQty > 0 ? 'qty-increase' : 'qty-decrease';
        $qtySign = $deltaQty > 0 ? '+' : '';

        echo '<div class="', $itemClass, '">';
        echo '<div class="adjustment-header">';
        echo '<div class="adjustment-title">', $salesRepName, '</div>';
        echo '<div class="adjustment-badge ', $badgeClass, '">', $badgeText, '</div>';
        echo '</div>';
        echo '<div class="adjustment-details">';
        echo '<strong>', $productName, '</strong> (', $sku, ')<br>';
        echo '<span class="qty-change ', $qtyClass, '">', $qtySign, number_format($deltaQty, 1), ' units</span> ';
        echo '• ', ucwords(str_replace('_', ' ', $reason));
        echo '</div>';

        if (!$initiatorConfirmed) {
            echo '<form method="POST" class="otp-form">';
            echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
            echo '<input type="hidden" name="action" value="confirm_otp">';
            echo '<input type="hidden" name="adjustment_id" value="', $adjustmentId, '">';
            echo '<input type="text" name="otp" class="otp-input" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off">';
            echo '<button type="submit" class="btn-confirm">✅ Confirm</button>';
            echo '</form>';
        } else {
            echo '<div style="margin-top:12px;padding:8px;background:#fef3c7;border-radius:6px;text-align:center;font-size:0.85rem;color:#92400e;">';
            echo '✓ You confirmed. Waiting for sales rep.';
            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';
}

echo '</div>';

echo '</div>'; // End content-grid

echo '<div class="card">';
echo '<h2>🧾 Recent Completed Adjustments</h2>';
if (empty($recentAdjustments)) {
    echo '<div class="empty-state">No completed adjustments yet.</div>';
} else {
    echo '<table class="data-table">';
    echo '<thead><tr><th>When</th><th>Sales Rep</th><th>Product</th><th>Change</th><th>Reason</th><th>Initiator</th></tr></thead>';
    echo '<tbody>';
    foreach ($recentAdjustments as $adj) {
        $deltaQty = (float)$adj['delta_qty'];
        $qtyClass = $deltaQty > 0 ? 'qty-increase' : 'qty-decrease';
        $qtySign = $deltaQty > 0 ? '+' : '';
        $initiatorLabel = $adj['initiator_type'] === 'warehouse_manager' ? 'Warehouse' : 'Admin';
        $initiatorName = $adj['initiator_name'] ? $initiatorLabel . ' · ' . $adj['initiator_name'] : $initiatorLabel;
        echo '<tr>';
        echo '<td>' . htmlspecialchars(date('M d, Y g:i A', strtotime($adj['completed_at'])), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($adj['sales_rep_name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td><strong>' . htmlspecialchars($adj['product_name'], ENT_QUOTES, 'UTF-8') . '</strong><br><span style="color:var(--muted);font-size:0.85rem;">' . htmlspecialchars($adj['sku'], ENT_QUOTES, 'UTF-8') . '</span></td>';
        echo '<td><span class="' . $qtyClass . '">' . $qtySign . number_format($deltaQty, 1) . '</span></td>';
        echo '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', (string)$adj['reason'])), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($initiatorName, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

echo '</div>'; // End page-container

admin_render_layout_end();
