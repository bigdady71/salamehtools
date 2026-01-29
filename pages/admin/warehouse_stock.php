<?php

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Warehouse';

// Handle movement logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'log_movement') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $kind = $_POST['kind'] ?? '';
        $qty = (float)($_POST['qty'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $ref = trim($_POST['ref'] ?? '');

        if ($productId > 0 && in_array($kind, ['in', 'out', 'adjust']) && $qty != 0) {
            try {
                $pdo->beginTransaction();

                // Log the movement
                $stmt = $pdo->prepare("
                    INSERT INTO warehouse_movements (product_id, kind, qty, reason, ref, created_by)
                    VALUES (:product_id, :kind, :qty, :reason, :ref, :created_by)
                ");
                $stmt->execute([
                    ':product_id' => $productId,
                    ':kind' => $kind,
                    ':qty' => $qty,
                    ':reason' => $reason,
                    ':ref' => $ref,
                    ':created_by' => $user['id']
                ]);

                // Update product quantity
                $stmt = $pdo->prepare("
                    UPDATE products
                    SET quantity_on_hand = quantity_on_hand + :qty
                    WHERE id = :product_id
                ");
                $stmt->execute([
                    ':qty' => $qty,
                    ':product_id' => $productId
                ]);

                $pdo->commit();
                flash('success', 'Movement logged successfully.');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('error', 'Failed to log movement: ' . $e->getMessage());
            }
        } else {
            flash('error', 'Invalid movement data.');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get filter parameters
$alertFilter = $_GET['alert'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

// Calculate stock health KPIs
$kpis = $pdo->query("
    SELECT
        COUNT(*) AS total_skus,
        SUM(CASE WHEN safety_stock IS NOT NULL AND quantity_on_hand < safety_stock THEN 1 ELSE 0 END) AS below_safety,
        SUM(CASE WHEN reorder_point IS NOT NULL AND quantity_on_hand <= reorder_point THEN 1 ELSE 0 END) AS below_reorder,
        SUM(quantity_on_hand * wholesale_price_usd) AS total_value_usd
    FROM products
    WHERE is_active = 1
")->fetch(PDO::FETCH_ASSOC);

// Build stock query
$stockQuery = "
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.quantity_on_hand,
        p.safety_stock,
        p.reorder_point,
        p.wholesale_price_usd,
        (p.quantity_on_hand * p.wholesale_price_usd) AS stock_value,
        CASE
            WHEN p.safety_stock IS NOT NULL AND p.quantity_on_hand < p.safety_stock THEN 'critical'
            WHEN p.reorder_point IS NOT NULL AND p.quantity_on_hand <= p.reorder_point THEN 'warning'
            ELSE 'ok'
        END AS alert_level
    FROM products p
    WHERE p.is_active = 1
";

$params = [];

if ($searchQuery !== '') {
    $stockQuery .= " AND (p.sku LIKE :search OR p.item_name LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$stockQuery .= " ORDER BY
    CASE
        WHEN p.safety_stock IS NOT NULL AND p.quantity_on_hand < p.safety_stock THEN 1
        WHEN p.reorder_point IS NOT NULL AND p.quantity_on_hand <= p.reorder_point THEN 2
        ELSE 3
    END,
    p.item_name
    LIMIT 100
";

$stmt = $pdo->prepare($stockQuery);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter products by alert level if needed
if ($alertFilter !== 'all') {
    $products = array_filter($products, function($p) use ($alertFilter) {
        return $p['alert_level'] === $alertFilter;
    });
}

// Get recent movements
$recentMovements = $pdo->query("
    SELECT
        wm.id,
        wm.kind,
        wm.qty,
        wm.reason,
        wm.ref,
        wm.created_at,
        p.sku,
        p.item_name,
        u.name AS created_by_name
    FROM warehouse_movements wm
    JOIN products p ON wm.product_id = p.id
    JOIN users u ON wm.created_by = u.id
    ORDER BY wm.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Get all products for movement form
$allProducts = $pdo->query("SELECT id, sku, item_name FROM products WHERE is_active = 1 ORDER BY item_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Warehouse Stock',
    'subtitle' => 'Monitor inventory health and track movements',
    'active' => 'warehouse',
    'user' => $user,
]);

$flashes = consume_flashes();
?>

<style>
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 32px;
    }
    .kpi-card {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 20px;
        border-left: 4px solid var(--accent);
    }
    .kpi-card.critical { border-left-color: #ff5c7a; }
    .kpi-card.warning { border-left-color: #ffd166; }
    .kpi-card.ok { border-left-color: #6ee7b7; }
    .kpi-label {
        font-size: 0.85rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 8px;
    }
    .kpi-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text);
    }
    .stock-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--bg-panel);
        border-radius: 12px;
        overflow: hidden;
    }
    .stock-table th {
        background: var(--bg-panel-alt);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
    }
    .stock-table td {
        padding: 12px;
        border-top: 1px solid var(--border);
    }
    .stock-table tr:hover {
        background: var(--bg-panel-alt);
    }
    .alert-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .alert-badge.critical { background: rgba(255, 92, 122, 0.2); color: #ff5c7a; }
    .alert-badge.warning { background: rgba(255, 209, 102, 0.2); color: #ffd166; }
    .alert-badge.ok { background: rgba(110, 231, 183, 0.2); color: #6ee7b7; }
    .movement-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .movement-badge.in { background: rgba(110, 231, 183, 0.2); color: #6ee7b7; }
    .movement-badge.out { background: rgba(255, 159, 102, 0.2); color: #ff9f66; }
    .movement-badge.adjust { background: rgba(74, 125, 255, 0.2); color: #4a7dff; }
    .filter-bar {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }
    .form-input, .form-select {
        padding: 8px 12px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text);
    }
    .btn {
        padding: 10px 20px;
        background: #6666ff;
        color: #ffffff;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-block;
    }
    .btn:hover {
        background: #003366;
    }
    .btn-sm {
        padding: 6px 12px;
        font-size: 0.85rem;
    }
    .section {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
    }
    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 16px;
    }
    .movement-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
        margin-top: 16px;
    }
    .form-group {
        display: flex;
        flex-direction: column;
    }
    .form-label {
        font-weight: 600;
        margin-bottom: 4px;
        font-size: 0.9rem;
    }
    .result-box {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 16px;
    }
    .result-success {
        background: rgba(110, 231, 183, 0.2);
        border: 1px solid rgba(110, 231, 183, 0.3);
        color: #6ee7b7;
    }
    .result-error {
        background: rgba(255, 92, 122, 0.2);
        border: 1px solid rgba(255, 92, 122, 0.3);
        color: #ff5c7a;
    }
    .qty-cell {
        font-family: 'Courier New', monospace;
        font-weight: 600;
    }
</style>

<?php foreach ($flashes as $flash): ?>
    <div class="result-box result-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endforeach; ?>

<div class="kpi-grid">
    <div class="kpi-card ok">
        <div class="kpi-label">Total SKUs</div>
        <div class="kpi-value"><?= number_format((int)$kpis['total_skus']) ?></div>
    </div>
    <div class="kpi-card critical">
        <div class="kpi-label">Below Safety Stock</div>
        <div class="kpi-value"><?= number_format((int)$kpis['below_safety']) ?></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-label">At/Below Reorder Point</div>
        <div class="kpi-value"><?= number_format((int)$kpis['below_reorder']) ?></div>
    </div>
    <div class="kpi-card ok">
        <div class="kpi-label">Total Stock Value</div>
        <div class="kpi-value">$<?= number_format((float)$kpis['total_value_usd'], 0) ?></div>
    </div>
</div>

<div class="filter-bar">
    <form method="get" style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1; align-items: center;">
        <div>
            <input
                type="text"
                name="search"
                class="form-input"
                placeholder="Search SKU or name..."
                value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                style="width: 250px;"
            >
        </div>
        <div>
            <select name="alert" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $alertFilter === 'all' ? 'selected' : '' ?>>All Products</option>
                <option value="critical" <?= $alertFilter === 'critical' ? 'selected' : '' ?>>Critical Only</option>
                <option value="warning" <?= $alertFilter === 'warning' ? 'selected' : '' ?>>Warning Only</option>
                <option value="ok" <?= $alertFilter === 'ok' ? 'selected' : '' ?>>OK Only</option>
            </select>
        </div>
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if ($searchQuery !== '' || $alertFilter !== 'all'): ?>
            <a href="warehouse_stock.php" class="btn btn-sm" style="background: var(--bg-panel-alt); color: var(--text);">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="section">
    <h2 class="section-title">Stock Levels</h2>
    <table class="stock-table">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product Name</th>
                <th>On Hand</th>
                <th>Safety Stock</th>
                <th>Reorder Point</th>
                <th>Unit Value</th>
                <th>Stock Value</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td style="font-family: 'Courier New', monospace;"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="qty-cell"><?= number_format((float)$product['quantity_on_hand'], 2) ?></td>
                    <td class="qty-cell"><?= $product['safety_stock'] ? number_format((float)$product['safety_stock'], 2) : '—' ?></td>
                    <td class="qty-cell"><?= $product['reorder_point'] ? number_format((float)$product['reorder_point'], 2) : '—' ?></td>
                    <td class="qty-cell">$<?= number_format((float)$product['wholesale_price_usd'], 2) ?></td>
                    <td class="qty-cell">$<?= number_format((float)$product['stock_value'], 2) ?></td>
                    <td>
                        <span class="alert-badge <?= $product['alert_level'] ?>">
                            <?= strtoupper($product['alert_level']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($products)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 32px; color: var(--muted);">
                        No products found matching your filters.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2 class="section-title">Recent Movements</h2>

    <details style="margin-bottom: 20px;">
        <summary style="cursor: pointer; font-weight: 600; padding: 8px 0;">+ Log New Movement</summary>
        <form method="post" class="movement-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="log_movement">

            <div class="form-group">
                <label class="form-label">Product *</label>
                <select name="product_id" class="form-select" required>
                    <option value="">Select product...</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">
                            <?= htmlspecialchars($p['sku'] . ' - ' . $p['item_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Type *</label>
                <select name="kind" class="form-select" required>
                    <option value="in">IN (Receiving)</option>
                    <option value="out">OUT (Shipment)</option>
                    <option value="adjust">ADJUST (Correction)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Quantity * (+ or -)</label>
                <input type="number" name="qty" class="form-input" step="0.001" required placeholder="e.g., 100 or -50">
            </div>

            <div class="form-group">
                <label class="form-label">Reason</label>
                <input type="text" name="reason" class="form-input" placeholder="e.g., Purchase order #1234">
            </div>

            <div class="form-group">
                <label class="form-label">Reference</label>
                <input type="text" name="ref" class="form-input" placeholder="e.g., PO-1234">
            </div>

            <div class="form-group" style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn" style="width: 100%;">Log Movement</button>
            </div>
        </form>
    </details>

    <table class="stock-table">
        <thead>
            <tr>
                <th>Date/Time</th>
                <th>Type</th>
                <th>Product</th>
                <th>Quantity</th>
                <th>Reason</th>
                <th>Reference</th>
                <th>Created By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentMovements as $movement): ?>
                <tr>
                    <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($movement['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="movement-badge <?= $movement['kind'] ?>"><?= strtoupper($movement['kind']) ?></span></td>
                    <td>
                        <div style="font-family: 'Courier New', monospace; font-size: 0.85rem; color: var(--muted);">
                            <?= htmlspecialchars($movement['sku'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?= htmlspecialchars($movement['item_name'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="qty-cell" style="color: <?= $movement['qty'] > 0 ? '#6ee7b7' : '#ff9f66' ?>;">
                        <?= $movement['qty'] > 0 ? '+' : '' ?><?= number_format((float)$movement['qty'], 2) ?>
                    </td>
                    <td><?= htmlspecialchars($movement['reason'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($movement['ref'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($movement['created_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($recentMovements)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 32px; color: var(--muted);">
                        No movements logged yet.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php admin_render_layout_end(); ?>
