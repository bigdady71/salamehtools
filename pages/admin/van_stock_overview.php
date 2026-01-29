<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';

// Admin authentication
require_login();
$user = auth_user();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'accountant'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Sales Rep Van Stocks';

// Filters
$repFilter = (int)($_GET['rep_id'] ?? 0);
$searchFilter = trim((string)($_GET['search'] ?? ''));
$stockAlert = (string)($_GET['alert'] ?? 'all');

// Get all sales reps for filter dropdown
$repsStmt = $pdo->query("
    SELECT id, name, email
    FROM users
    WHERE role = 'sales_rep' AND is_active = 1
    ORDER BY name ASC
");
$salesReps = $repsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for van stocks
$whereClauses = ["u.role = 'sales_rep'"];
$params = [];

if ($repFilter > 0) {
    $whereClauses[] = "s.salesperson_id = :rep_id";
    $params[':rep_id'] = $repFilter;
}

if ($searchFilter !== '') {
    $whereClauses[] = "(p.item_name LIKE :search OR p.sku LIKE :search OR u.name LIKE :search)";
    $params[':search'] = '%' . $searchFilter . '%';
}

switch ($stockAlert) {
    case 'out':
        $whereClauses[] = "s.qty_on_hand = 0";
        break;
    case 'low':
        $whereClauses[] = "s.qty_on_hand > 0 AND s.qty_on_hand <= 5";
        break;
    case 'ok':
        $whereClauses[] = "s.qty_on_hand > 5";
        break;
}

$whereSQL = implode(' AND ', $whereClauses);

// Get van stock data with rep info
$stockQuery = "
    SELECT
        u.id AS rep_id,
        u.name AS rep_name,
        u.email AS rep_email,
        p.id AS product_id,
        p.sku,
        p.item_name,
        p.topcat AS category,
        p.wholesale_price_usd,
        s.qty_on_hand,
        (s.qty_on_hand * p.wholesale_price_usd) AS stock_value,
        s.updated_at
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    INNER JOIN users u ON u.id = s.salesperson_id
    WHERE {$whereSQL}
    ORDER BY u.name ASC, p.item_name ASC
    LIMIT 500
";

$stockStmt = $pdo->prepare($stockQuery);
$stockStmt->execute($params);
$stocks = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats by rep
$repStats = [];
foreach ($stocks as $stock) {
    $repId = (int)$stock['rep_id'];
    if (!isset($repStats[$repId])) {
        $repStats[$repId] = [
            'name' => $stock['rep_name'],
            'email' => $stock['rep_email'],
            'total_skus' => 0,
            'total_units' => 0,
            'total_value' => 0,
            'out_of_stock' => 0,
            'low_stock' => 0,
        ];
    }

    $repStats[$repId]['total_skus']++;
    $repStats[$repId]['total_units'] += (float)$stock['qty_on_hand'];
    $repStats[$repId]['total_value'] += (float)$stock['stock_value'];

    if ((float)$stock['qty_on_hand'] == 0) {
        $repStats[$repId]['out_of_stock']++;
    } elseif ((float)$stock['qty_on_hand'] <= 5) {
        $repStats[$repId]['low_stock']++;
    }
}

// Overall summary
$totalReps = count($repStats);
$totalSkus = array_sum(array_column($repStats, 'total_skus'));
$totalUnits = array_sum(array_column($repStats, 'total_units'));
$totalValue = array_sum(array_column($repStats, 'total_value'));

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Sales Rep Van Stock Overview',
    'subtitle' => 'Monitor field inventory across all sales representatives',
    'user' => $user,
    'active' => 'van_stock',
]);

?>

<!-- Overall Summary Cards -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-label">Active Sales Reps</div>
        <div class="stat-value"><?= $totalReps ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total SKUs Stocked</div>
        <div class="stat-value"><?= number_format($totalSkus) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Units</div>
        <div class="stat-value"><?= number_format($totalUnits, 1) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Stock Value</div>
        <div class="stat-value">$<?= number_format($totalValue, 2) ?></div>
    </div>
</div>

<!-- Rep Summary Cards -->
<?php if (!empty($repStats)): ?>
<div style="margin-bottom: 32px;">
    <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 16px;">Sales Rep Summary</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
        <?php foreach ($repStats as $repId => $stats): ?>
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
            <div style="font-weight: 600; font-size: 1.05rem; margin-bottom: 4px;"><?= htmlspecialchars($stats['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size: 0.85rem; color: #6b7280; margin-bottom: 12px;"><?= htmlspecialchars($stats['email'], ENT_QUOTES, 'UTF-8') ?></div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.9rem;">
                <div>
                    <div style="color: #6b7280;">SKUs:</div>
                    <div style="font-weight: 600;"><?= $stats['total_skus'] ?></div>
                </div>
                <div>
                    <div style="color: #6b7280;">Units:</div>
                    <div style="font-weight: 600;"><?= number_format($stats['total_units'], 1) ?></div>
                </div>
                <div>
                    <div style="color: #6b7280;">Value:</div>
                    <div style="font-weight: 600;">$<?= number_format($stats['total_value'], 2) ?></div>
                </div>
                <div>
                    <div style="color: #6b7280;">Alerts:</div>
                    <div>
                        <?php if ($stats['out_of_stock'] > 0): ?>
                            <span style="color: #dc2626; font-size: 0.85rem;">🔴 <?= $stats['out_of_stock'] ?> out</span>
                        <?php endif; ?>
                        <?php if ($stats['low_stock'] > 0): ?>
                            <span style="color: #f59e0b; font-size: 0.85rem;">⚠️ <?= $stats['low_stock'] ?> low</span>
                        <?php endif; ?>
                        <?php if ($stats['out_of_stock'] == 0 && $stats['low_stock'] == 0): ?>
                            <span style="color: #10b981; font-size: 0.85rem;">✓ OK</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="?rep_id=<?= $repId ?>" class="btn" style="width: 100%; margin-top: 12px; text-align: center; display: block;">View Stock</a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="filters" style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e5e7eb;">
    <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Sales Rep</label>
            <select name="rep_id" class="form-control">
                <option value="0">All Sales Reps</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= $rep['id'] ?>" <?= $repFilter === (int)$rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Search Product/SKU</label>
            <input type="text" name="search" value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Product name, SKU..." class="form-control">
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Stock Alert</label>
            <select name="alert" class="form-control">
                <option value="all" <?= $stockAlert === 'all' ? 'selected' : '' ?>>All Stock Levels</option>
                <option value="out" <?= $stockAlert === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                <option value="low" <?= $stockAlert === 'low' ? 'selected' : '' ?>>Low Stock (≤5)</option>
                <option value="ok" <?= $stockAlert === 'ok' ? 'selected' : '' ?>>OK Stock (>5)</option>
            </select>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="?" class="btn">Clear</a>
        </div>
    </form>
</div>

<!-- Stock Details Table -->
<?php if (!empty($stocks)): ?>
<div class="table-container" style="background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Sales Rep</th>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Qty on Hand</th>
                <th class="text-right">Stock Value</th>
                <th class="text-center">Status</th>
                <th class="text-center">Last Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stocks as $stock):
                $qty = (float)$stock['qty_on_hand'];
                $statusBadge = '';
                $statusColor = '';

                if ($qty == 0) {
                    $statusBadge = 'Out of Stock';
                    $statusColor = 'background: rgba(220, 38, 38, 0.1); color: #dc2626;';
                } elseif ($qty <= 5) {
                    $statusBadge = 'Low Stock';
                    $statusColor = 'background: rgba(245, 158, 11, 0.1); color: #f59e0b;';
                } else {
                    $statusBadge = 'In Stock';
                    $statusColor = 'background: rgba(16, 185, 129, 0.1); color: #10b981;';
                }
            ?>
            <tr>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($stock['rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($stock['rep_email'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($stock['item_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><code><?= htmlspecialchars($stock['sku'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($stock['category'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-right">$<?= number_format((float)$stock['wholesale_price_usd'], 2) ?></td>
                <td class="text-right"><strong><?= number_format($qty, 1) ?></strong></td>
                <td class="text-right">$<?= number_format((float)$stock['stock_value'], 2) ?></td>
                <td class="text-center">
                    <span class="badge" style="<?= $statusColor ?>"><?= $statusBadge ?></span>
                </td>
                <td class="text-center" style="font-size: 0.85rem; color: #6b7280;">
                    <?= $stock['updated_at'] ? date('M d, Y', strtotime($stock['updated_at'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="margin-top: 16px; color: #6b7280; font-size: 0.9rem;">
    Showing <?= count($stocks) ?> stock items
</div>

<?php else: ?>
<div class="empty-state" style="background: white; border-radius: 8px; padding: 48px; text-align: center; border: 1px solid #e5e7eb;">
    <div style="font-size: 3rem; margin-bottom: 16px;">📦</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">No Stock Data Found</h3>
    <p style="color: #6b7280;">No sales reps have van stock matching your filters.</p>
</div>
<?php endif; ?>

<?php
admin_render_layout_end();
?>
