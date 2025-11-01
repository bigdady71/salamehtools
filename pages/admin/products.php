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
$title = 'Admin · Products';

// Pagination & filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 75;
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$activeFilter = $_GET['active'] ?? '';

// Build WHERE conditions
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR p.second_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($category !== '') {
    $where[] = "(p.topcat_name = :category OR p.midcat_name = :category)";
    $params[':category'] = $category;
}

if ($stockFilter === 'low') {
    $where[] = "p.quantity_on_hand <= GREATEST(p.min_quantity, 5)";
} elseif ($stockFilter === 'gt1') {
    $where[] = "p.quantity_on_hand > 1";
} elseif ($stockFilter === 'out') {
    $where[] = "p.quantity_on_hand = 0";
}

if ($activeFilter !== '') {
    $where[] = "p.is_active = :active";
    $params[':active'] = (int)$activeFilter;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereClause}");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);
$page = min($page, max(1, $totalPages));
$offset = ($page - 1) * $perPage;

// Get products
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.sku,
        p.item_name,
        p.second_name,
        p.unit,
        p.topcat_name,
        p.midcat_name,
        p.sale_price_usd,
        p.wholesale_price_usd,
        p.min_quantity,
        p.quantity_on_hand,
        p.is_active
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.sku ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$catStmt = $pdo->query("
    SELECT DISTINCT topcat_name AS category FROM products WHERE topcat_name IS NOT NULL AND topcat_name != ''
    UNION
    SELECT DISTINCT midcat_name FROM products WHERE midcat_name IS NOT NULL AND midcat_name != ''
    ORDER BY category
");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_active') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        
        try {
            $updateStmt = $pdo->prepare("UPDATE products SET is_active = :status, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute([':status' => $newStatus, ':id' => $productId]);
            flash('success', 'Product status updated successfully.');
        } catch (PDOException $e) {
            flash('error', 'Failed to update product status.');
        }
        
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'update_price') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $salePrice = (float)($_POST['sale_price'] ?? 0);
        $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0);
        
        try {
            $updateStmt = $pdo->prepare("
                UPDATE products 
                SET sale_price_usd = :sale, wholesale_price_usd = :wholesale, updated_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':sale' => $salePrice,
                ':wholesale' => $wholesalePrice,
                ':id' => $productId
            ]);
            flash('success', 'Prices updated successfully.');
        } catch (PDOException $e) {
            flash('error', 'Failed to update prices.');
        }
        
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Summary stats
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN quantity_on_hand = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand <= GREATEST(min_quantity, 5) THEN 1 ELSE 0 END) as low_stock
    FROM products
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$flashes = consume_flashes();

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Products',
    'subtitle' => 'Manage your product catalogue and inventory',
    'active' => 'products',
    'user' => $user,
    'actions' => [
        ['label' => 'Import Excel', 'href' => 'products_import.php', 'variant' => 'primary'],
        ['label' => 'Export CSV', 'href' => 'products_export.php'],
    ],
]);

admin_render_flashes($flashes);
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: var(--bg-panel-alt);
        padding: 20px;
        border-radius: 12px;
        border: 1px solid var(--border);
    }
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        margin: 8px 0;
    }
    .stat-label {
        color: var(--muted);
        font-size: 0.9rem;
    }
    .filters {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .filter-input {
        padding: 8px 12px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 8px;
        color: var(--text);
        font-size: 0.9rem;
    }
    .product-table {
        background: var(--bg-panel-alt);
        border-radius: 12px;
        overflow: hidden;
    }
    .product-table table {
        width: 100%;
        border-collapse: collapse;
    }
    .product-table th {
        background: rgba(0,0,0,0.3);
        padding: 12px;
        text-align: left;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
    }
    .product-table td {
        padding: 12px;
        border-top: 1px solid var(--border);
    }
    .product-table tr:hover {
        background: rgba(255,255,255,0.02);
    }
    .stock-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .stock-ok { background: rgba(110, 231, 183, 0.2); color: #6ee7b7; }
    .stock-low { background: rgba(255, 209, 102, 0.2); color: #ffd166; }
    .stock-out { background: rgba(255, 92, 122, 0.2); color: #ff5c7a; }
    .price-input {
        width: 100px;
        padding: 4px 8px;
        background: var(--bg-panel);
        border: 1px solid var(--border);
        border-radius: 4px;
        color: var(--text);
        font-size: 0.9rem;
    }
    .inline-form {
        display: inline-flex;
        gap: 4px;
        align-items: center;
    }
    .pagination {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-top: 24px;
    }
    .pagination a, .pagination span {
        padding: 8px 12px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text);
        text-decoration: none;
        font-size: 0.9rem;
    }
    .pagination a:hover {
        background: var(--accent);
        color: #000;
    }
    .pagination .active {
        background: var(--accent-2);
        color: #fff;
    }
</style>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Products</div>
        <div class="stat-value"><?= number_format($stats['total']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active</div>
        <div class="stat-value" style="color: var(--success)"><?= number_format($stats['active']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Low Stock</div>
        <div class="stat-value" style="color: var(--warning)"><?= number_format($stats['low_stock']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Out of Stock</div>
        <div class="stat-value" style="color: var(--danger)"><?= number_format($stats['out_of_stock']) ?></div>
    </div>
</div>

<section class="card">
    <form method="get" class="filters">
        <input type="hidden" name="path" value="admin/products">
        <input type="text" name="search" placeholder="Search SKU, name..." 
               value="<?= htmlspecialchars($search) ?>" class="filter-input" style="flex: 1; min-width: 200px;">
        
        <select name="category" class="filter-input">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select name="stock" class="filter-input">
            <option value="">All Stock Levels</option>
            <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="gt1" <?= $stockFilter === 'gt1' ? 'selected' : '' ?>>Stock &gt; 1</option>
            <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
        </select>
        
        <select name="active" class="filter-input">
            <option value="">All Status</option>
            <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inactive</option>
        </select>
        
        <button type="submit" class="btn">Filter</button>
        <a href="?path=admin/products" class="btn">Clear</a>
    </form>

    <div class="product-table">
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th>Retail Price</th>
                    <th>Wholesale Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--muted);">
                            No products found. Try adjusting your filters or <a href="products_import.php" style="color: var(--accent)">import products</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.85rem;">
                                <?= htmlspecialchars($product['sku'] ?: '—') ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($product['item_name']) ?></strong>
                                <?php if ($product['second_name']): ?>
                                    <br><small style="color: var(--muted)"><?= htmlspecialchars($product['second_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?= htmlspecialchars($product['topcat_name'] ?: '—') ?>
                                <?php if ($product['midcat_name']): ?>
                                    <br><small style="color: var(--muted)"><?= htmlspecialchars($product['midcat_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($product['unit'] ?: '—') ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="update_price">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    $<input type="number" name="sale_price" value="<?= $product['sale_price_usd'] ?>" 
                                           step="0.01" min="0" class="price-input">
                                </form>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="update_price">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    $<input type="number" name="wholesale_price" value="<?= $product['wholesale_price_usd'] ?>" 
                                           step="0.01" min="0" class="price-input">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.8rem;">Save</button>
                                </form>
                            </td>
                            <td>
                                <?php 
                                $qty = (float)$product['quantity_on_hand'];
                                $min = (float)$product['min_quantity'];
                                $stockClass = 'stock-ok';
                                if ($qty == 0) $stockClass = 'stock-out';
                                elseif ($qty <= max($min, 5)) $stockClass = 'stock-low';
                                ?>
                                <span class="stock-badge <?= $stockClass ?>">
                                    <?= number_format($qty, 2) ?>
                                </span>
                                <?php if ($min > 0): ?>
                                    <br><small style="color: var(--muted)">Min: <?= number_format($min, 2) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($product['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $product['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.8rem;">
                                        <?= $product['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?path=admin/products&page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">First</a>
                <a href="?path=admin/products&page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">Previous</a>
            <?php endif; ?>
            
            <?php 
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++): 
            ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?path=admin/products&page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?path=admin/products&page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">Next</a>
                <a href="?path=admin/products&page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php admin_render_layout_end(); ?>
