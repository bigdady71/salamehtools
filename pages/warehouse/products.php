<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/csv_export.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = trim($_GET['search'] ?? '');
    $stockStatus = $_GET['stock_status'] ?? 'all';
    $category = trim($_GET['category'] ?? '');

    // Build WHERE clause (same as main query)
    $where = ['p.is_active = 1'];
    $params = [];

    if ($search !== '') {
        $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR p.barcode LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($stockStatus === 'low') {
        $where[] = "p.quantity_on_hand <= p.reorder_point AND p.quantity_on_hand > 0";
    } elseif ($stockStatus === 'out') {
        $where[] = "p.quantity_on_hand <= 0";
    } elseif ($stockStatus === 'in_stock') {
        $where[] = "p.quantity_on_hand > p.reorder_point";
    }

    if ($category !== '') {
        $where[] = "(p.topcat_name = :category OR p.midcat_name = :category)";
        $params[':category'] = $category;
    }

    $whereClause = implode(' AND ', $where);

    // Get products for export (warehouse stock only)
    $exportStmt = $pdo->prepare("
        SELECT
            p.sku as 'SKU',
            p.item_name as 'Product Name',
            p.topcat_name as 'Category',
            p.midcat_name as 'Subcategory',
            p.unit as 'Unit',
            COALESCE(p.quantity_on_hand, 0) as 'Qty in Stock',
            p.reorder_point as 'Reorder Point'
        FROM products p
        WHERE {$whereClause}
        ORDER BY p.item_name ASC
    ");
    $exportStmt->execute($params);
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'warehouse_products_' . date('Y-m-d_His');
    exportToCSV($exportData, $filename);
}

// Handle search and filters
$search = trim($_GET['search'] ?? '');
$stockStatus = $_GET['stock_status'] ?? 'all';
$category = trim($_GET['category'] ?? '');

// Build WHERE clause
$where = ['p.is_active = 1'];
$params = [];

if ($search !== '') {
    $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR p.barcode LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($stockStatus === 'low') {
    $where[] = "p.quantity_on_hand <= p.reorder_point AND p.quantity_on_hand > 0";
} elseif ($stockStatus === 'out') {
    $where[] = "p.quantity_on_hand <= 0";
} elseif ($stockStatus === 'in_stock') {
    $where[] = "p.quantity_on_hand > p.reorder_point";
}

if ($category !== '') {
    $where[] = "(p.topcat_name = :category OR p.midcat_name = :category)";
    $params[':category'] = $category;
}

$whereClause = implode(' AND ', $where);

// Get products with stock information (warehouse stock only - uses products.quantity_on_hand)
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat_name,
        p.midcat_name,
        p.unit,
        p.description,
        p.image_url,
        p.reorder_point,
        p.min_quantity,
        p.quantity_on_hand as qty_on_hand,
        p.updated_at as last_stock_update
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.item_name ASC
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $pdo->query("
    SELECT DISTINCT topcat_name as category
    FROM products
    WHERE topcat_name IS NOT NULL AND topcat_name != ''
    UNION
    SELECT DISTINCT midcat_name as category
    FROM products
    WHERE midcat_name IS NOT NULL AND midcat_name != ''
    ORDER BY category ASC
")->fetchAll(PDO::FETCH_COLUMN);

$title = 'Products - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Products & Inventory',
    'subtitle' => 'Manage product stock levels and warehouse locations',
    'user' => $user,
    'active' => 'products',
]);
?>

<!-- Filters -->
<div class="card" style="margin-bottom:24px;">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;align-items:end;">
        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="SKU, name, or barcode..."
                   style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Stock Status</label>
            <select name="stock_status" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
                <option value="all" <?= $stockStatus === 'all' ? 'selected' : '' ?>>All Products</option>
                <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>Low Stock</option>
                <option value="out" <?= $stockStatus === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
        </div>

        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Category</label>
            <select name="category" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $category === $cat ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn" style="flex:1;">Filter</button>
            <a href="products.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>

    <!-- Export Button -->
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <div style="color:var(--muted);font-size:0.9rem;">
            Showing <?= count($products) ?> product(s)
        </div>
        <a href="?export=csv<?= $search ? '&search=' . urlencode($search) : '' ?><?= $stockStatus !== 'all' ? '&stock_status=' . urlencode($stockStatus) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?>"
           class="btn btn-secondary"
           style="background:#059669;color:white;border-color:#059669;">
            📥 Export to CSV
        </a>
    </div>
</div>

<!-- Summary Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
    <?php
    $totalProducts = count($products);
    $inStock = 0;
    $lowStock = 0;
    $outOfStock = 0;

    foreach ($products as $p) {
        $qty = (float)($p['qty_on_hand'] ?? 0);
        $reorder = (float)($p['reorder_point'] ?? 0);

        if ($qty <= 0) {
            $outOfStock++;
        } elseif ($qty <= $reorder) {
            $lowStock++;
        } else {
            $inStock++;
        }
    }
    ?>

    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= $totalProducts ?></div>
        <div style="font-size:0.9rem;color:var(--muted);">Total Products</div>
    </div>

    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:2rem;font-weight:700;color:var(--success);"><?= $inStock ?></div>
        <div style="font-size:0.9rem;color:var(--muted);">In Stock</div>
    </div>

    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:2rem;font-weight:700;color:var(--warning);"><?= $lowStock ?></div>
        <div style="font-size:0.9rem;color:var(--muted);">Low Stock</div>
    </div>

    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:2rem;font-weight:700;color:var(--danger);"><?= $outOfStock ?></div>
        <div style="font-size:0.9rem;color:var(--muted);">Out of Stock</div>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h2 style="margin:0;">Product List (<?= $totalProducts ?>)</h2>
        <a href="receiving.php" class="btn btn-success">📥 Receive Stock</a>
    </div>

    <?php if (empty($products)): ?>
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            No products found matching your criteria
        </p>
    <?php else: ?>
        <!-- Products Grid - Simplified -->
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($products as $product): ?>
                <?php
                $qtyOnHand = (float)($product['qty_on_hand'] ?? 0);
                $reorderPoint = (float)($product['reorder_point'] ?? 0);

                // Determine status
                if ($qtyOnHand <= 0) {
                    $statusText = 'OUT';
                    $borderColor = '#dc2626';
                    $statusColor = '#dc2626';
                } elseif ($qtyOnHand <= $reorderPoint) {
                    $statusText = 'LOW';
                    $borderColor = '#f59e0b';
                    $statusColor = '#f59e0b';
                } else {
                    $statusText = 'OK';
                    $borderColor = '#e5e7eb';
                    $statusColor = '#059669';
                }
                ?>
                <div style="background:white;border:2px solid <?= $borderColor ?>;border-radius:8px;padding:12px;display:flex;gap:12px;align-items:center;">
                    <!-- Product Image -->
                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                             style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                    <?php else: ?>
                        <div style="width:60px;height:60px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.5rem;">
                            📦
                        </div>
                    <?php endif; ?>

                    <!-- Product Info -->
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:1rem;margin-bottom:2px;">
                            <?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="font-size:0.85rem;color:#6b7280;">
                            <span style="font-family:monospace;font-weight:600;color:#3b82f6;">
                                <?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($product['description']): ?>
                                &nbsp;•&nbsp;<?= htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            &nbsp;•&nbsp;<?= htmlspecialchars($product['topcat_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>

                    <!-- Stock Info -->
                    <div style="text-align:center;padding:8px 16px;background:#f3f4f6;border-radius:6px;min-width:100px;">
                        <div style="font-size:1.5rem;font-weight:700;line-height:1;color:<?= $statusColor ?>;">
                            <?= number_format($qtyOnHand, 0) ?>
                        </div>
                        <div style="font-size:0.7rem;color:#6b7280;margin-top:2px;">
                            <?= htmlspecialchars($product['unit'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="font-size:0.7rem;color:#9ca3af;margin-top:2px;">
                            Reorder: <?= number_format($reorderPoint, 0) ?>
                        </div>
                    </div>

                    <!-- Status -->
                    <div style="text-align:center;min-width:60px;">
                        <div style="font-weight:700;font-size:0.9rem;color:<?= $statusColor ?>;">
                            <?= $statusText ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <a href="upload_product_image.php?product_id=<?= $product['id'] ?>"
                           style="padding:6px 12px;background:#10b981;color:white;border-radius:6px;text-decoration:none;font-size:0.8rem;font-weight:500;text-align:center;white-space:nowrap;">
                            📸 <?= !empty($product['image_url']) ? 'Change' : 'Add' ?> Image
                        </a>
                        <a href="adjustments.php?product_id=<?= $product['id'] ?>"
                           style="padding:6px 12px;background:#3b82f6;color:white;border-radius:6px;text-decoration:none;font-size:0.8rem;font-weight:500;text-align:center;">
                            Adjust Stock
                        </a>
                    </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
warehouse_portal_render_layout_end();
