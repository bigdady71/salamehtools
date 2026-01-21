<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Filters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$stockStatus = trim($_GET['stock_status'] ?? '');
$sortBy = trim($_GET['sort'] ?? 'item_name');
$sortDir = strtoupper(trim($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get categories for filter
$categoriesStmt = $pdo->query("SELECT DISTINCT topcat_name FROM products WHERE topcat_name IS NOT NULL AND topcat_name != '' ORDER BY topcat_name");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Build query conditions
$conditions = ['1=1'];
$params = [];

if ($search !== '') {
    $conditions[] = "(p.sku LIKE :search OR p.item_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($category !== '') {
    $conditions[] = "p.topcat_name = :category";
    $params[':category'] = $category;
}

if ($stockStatus === 'in_stock') {
    $conditions[] = "p.quantity_on_hand > COALESCE(p.safety_stock, 10)";
} elseif ($stockStatus === 'low') {
    $conditions[] = "p.quantity_on_hand > 0 AND p.quantity_on_hand <= COALESCE(p.safety_stock, 10)";
} elseif ($stockStatus === 'out') {
    $conditions[] = "p.quantity_on_hand <= 0";
}

$whereClause = implode(' AND ', $conditions);

// Validate sort column
$allowedSorts = ['sku', 'item_name', 'topcat_name', 'quantity_on_hand', 'cost_price_usd', 'sale_price_usd', 'total_cost', 'total_retail'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'item_name';
}

// Get totals
$totalStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_products,
        COALESCE(SUM(p.quantity_on_hand), 0) as total_units,
        COALESCE(SUM(p.quantity_on_hand * COALESCE(p.cost_price_usd, 0)), 0) as total_cost_value,
        COALESCE(SUM(p.quantity_on_hand * COALESCE(p.sale_price_usd, 0)), 0) as total_retail_value
    FROM products p
    WHERE $whereClause AND p.is_active = 1
");
$totalStmt->execute($params);
$totals = $totalStmt->fetch(PDO::FETCH_ASSOC);

$totalProducts = (int)$totals['total_products'];
$totalPages = ceil($totalProducts / $perPage);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare("
        SELECT
            p.sku,
            p.item_name,
            p.topcat_name as category,
            p.quantity_on_hand,
            COALESCE(p.cost_price_usd, 0) as cost_price_usd,
            COALESCE(p.sale_price_usd, 0) as sale_price_usd,
            (p.quantity_on_hand * COALESCE(p.cost_price_usd, 0)) as total_cost,
            (p.quantity_on_hand * COALESCE(p.sale_price_usd, 0)) as total_retail
        FROM products p
        WHERE $whereClause AND p.is_active = 1
        ORDER BY $sortBy $sortDir
    ");
    $exportStmt->execute($params);
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['SKU', 'Product Name', 'Category', 'Qty on Hand', 'Cost Price (USD)', 'Sale Price (USD)', 'Total Cost Value', 'Total Retail Value']);

    foreach ($exportData as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

// Get products with calculated values
$orderByClause = $sortBy;
if ($sortBy === 'total_cost') {
    $orderByClause = "(p.quantity_on_hand * COALESCE(p.cost_price_usd, 0))";
} elseif ($sortBy === 'total_retail') {
    $orderByClause = "(p.quantity_on_hand * COALESCE(p.sale_price_usd, 0))";
}

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat_name as category,
        p.quantity_on_hand,
        p.safety_stock,
        COALESCE(p.cost_price_usd, 0) as cost_price_usd,
        COALESCE(p.sale_price_usd, 0) as sale_price_usd,
        (p.quantity_on_hand * COALESCE(p.cost_price_usd, 0)) as total_cost,
        (p.quantity_on_hand * COALESCE(p.sale_price_usd, 0)) as total_retail,
        p.image_url
    FROM products p
    WHERE $whereClause AND p.is_active = 1
    ORDER BY $orderByClause $sortDir
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for sort links
function sortLink(string $column, string $label, string $currentSort, string $currentDir): string
{
    $newDir = ($currentSort === $column && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentDir === 'ASC' ? ' ↑' : ' ↓';
    }
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = $newDir;
    unset($params['page']);
    return '<a href="?' . http_build_query($params) . '" style="color:inherit;text-decoration:none;">' . htmlspecialchars($label) . $arrow . '</a>';
}

accounting_render_layout_start([
    'title' => 'Inventory',
    'heading' => 'Inventory & Stock',
    'subtitle' => 'Stock levels and inventory valuation',
    'active' => 'inventory',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Total Products</div>
        <div class="value"><?= number_format($totalProducts) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Total Units</div>
        <div class="value"><?= number_format((int)$totals['total_units']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Cost Value</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_cost_value']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Retail Value</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_retail_value']) ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Search SKU or name..." value="<?= htmlspecialchars($search) ?>" class="filter-input" style="width: 200px;">

        <select name="category" class="filter-input">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="stock_status" class="filter-input">
            <option value="">All Stock Levels</option>
            <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
            <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="out" <?= $stockStatus === 'out' ? 'selected' : '' ?>>Out of Stock</option>
        </select>

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="inventory.php" class="btn">Clear</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="margin-left: auto;">Export CSV</a>
    </form>

    <table>
        <thead>
            <tr>
                <th style="width: 50px;"></th>
                <th><?= sortLink('sku', 'SKU', $sortBy, $sortDir) ?></th>
                <th><?= sortLink('item_name', 'Product Name', $sortBy, $sortDir) ?></th>
                <th><?= sortLink('topcat_name', 'Category', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('quantity_on_hand', 'Qty', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('cost_price_usd', 'Cost', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('sale_price_usd', 'Sale Price', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('total_cost', 'Total Cost', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('total_retail', 'Total Retail', $sortBy, $sortDir) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted">No products found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $qty = (int)$product['quantity_on_hand'];
                    $safety = (int)($product['safety_stock'] ?? 10);
                    $stockClass = 'badge-success';
                    if ($qty <= 0) {
                        $stockClass = 'badge-danger';
                    } elseif ($qty <= $safety) {
                        $stockClass = 'badge-warning';
                    }
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($product['image_url'])): ?>
                                <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 4px;"></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($product['sku']) ?></code></td>
                        <td><?= htmlspecialchars(substr($product['item_name'], 0, 40)) ?><?= strlen($product['item_name']) > 40 ? '...' : '' ?></td>
                        <td><?= htmlspecialchars($product['category'] ?? '-') ?></td>
                        <td class="text-right">
                            <span class="badge <?= $stockClass ?>"><?= number_format($qty) ?></span>
                        </td>
                        <td class="text-right"><?= format_currency_usd((float)$product['cost_price_usd']) ?></td>
                        <td class="text-right"><?= format_currency_usd((float)$product['sale_price_usd']) ?></td>
                        <td class="text-right"><?= format_currency_usd((float)$product['total_cost']) ?></td>
                        <td class="text-right"><?= format_currency_usd((float)$product['total_retail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <div style="margin-top: 20px; display: flex; gap: 8px; justify-content: center;">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm">Previous</a>
            <?php endif; ?>

            <span style="padding: 6px 12px; color: var(--muted);">
                Page <?= $page ?> of <?= $totalPages ?>
            </span>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
accounting_render_layout_end();
