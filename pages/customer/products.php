<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

$success = null;
$error = null;

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 1);

    if ($productId > 0 && $quantity > 0) {
        // Check if product exists and is active
        $productStmt = $pdo->prepare("SELECT id, item_name, min_quantity FROM products WHERE id = ? AND is_active = 1");
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $minQuantity = (float)$product['min_quantity'];
            if ($quantity < $minQuantity) {
                $error = 'Minimum quantity for this product is ' . number_format($minQuantity, 2);
            } else {
                // Check if already in cart
                $cartCheckStmt = $pdo->prepare("SELECT id, quantity FROM customer_cart WHERE customer_id = ? AND product_id = ?");
                $cartCheckStmt->execute([$customerId, $productId]);
                $existingCart = $cartCheckStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingCart) {
                    // Update existing cart item
                    $newQuantity = (float)$existingCart['quantity'] + $quantity;
                    $updateStmt = $pdo->prepare("UPDATE customer_cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$newQuantity, $existingCart['id']]);
                    $success = 'Updated quantity in cart!';
                } else {
                    // Insert new cart item
                    $insertStmt = $pdo->prepare("INSERT INTO customer_cart (customer_id, product_id, quantity) VALUES (?, ?, ?)");
                    $insertStmt->execute([$customerId, $productId, $quantity]);
                    $success = 'Added to cart successfully!';
                }
            }
        } else {
            $error = 'Product not found or unavailable.';
        }
    }
}

// Get filters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$inStock = isset($_GET['in_stock']) && $_GET['in_stock'] === '1';

// Build query
$where = ['p.is_active = 1'];
$params = [];

if ($search !== '') {
    $where[] = '(p.item_name LIKE ? OR p.second_name LIKE ? OR p.sku LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($category !== '') {
    $where[] = 'p.topcat = ?';
    $params[] = $category;
}

if ($inStock) {
    $where[] = 'p.quantity_on_hand > 0';
}

$whereClause = implode(' AND ', $where);

// Get products
$productsQuery = "
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.second_name,
        p.topcat,
        p.topcat_name,
        p.unit,
        p.sale_price_usd,
        p.wholesale_price_usd,
        p.min_quantity,
        p.quantity_on_hand,
        p.description
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.item_name ASC
    LIMIT 100
";

$productsStmt = $pdo->prepare($productsQuery);
$productsStmt->execute($params);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categoriesStmt = $pdo->query("
    SELECT DISTINCT topcat, topcat_name
    FROM products
    WHERE is_active = 1 AND topcat IS NOT NULL AND topcat != ''
    ORDER BY topcat_name ASC
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get cart count
$cartStmt = $pdo->prepare("SELECT COUNT(*) as count FROM customer_cart WHERE customer_id = ?");
$cartStmt->execute([$customerId]);
$cartCount = (int)$cartStmt->fetchColumn();

$title = 'Browse Products - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Browse Products',
    'subtitle' => 'Find and add products to your cart',
    'customer' => $customer,
    'active' => 'products',
    'actions' => [
        ['label' => '🛒 View Cart (' . $cartCount . ')', 'href' => 'cart.php', 'variant' => 'primary'],
    ]
]);

?>

<style>
.filters-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    margin-bottom: 24px;
}
.filters-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 16px;
    align-items: end;
}
@media (max-width: 900px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
}
.form-group {
    margin: 0;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 0.92rem;
    color: var(--text);
}
.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.2s;
}
.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
}
.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.checkbox-group label {
    margin: 0;
    cursor: pointer;
}
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.product-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    transition: all 0.3s;
    display: flex;
    flex-direction: column;
}
.product-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
}
.product-header {
    margin-bottom: 16px;
}
.product-header h3 {
    margin: 0 0 4px;
    font-size: 1.15rem;
    color: var(--text);
}
.product-header .sku {
    font-size: 0.8rem;
    color: var(--muted);
}
.product-category {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bg-panel-alt);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--accent);
    margin-bottom: 12px;
}
.product-description {
    font-size: 0.9rem;
    color: var(--muted);
    line-height: 1.5;
    margin-bottom: 16px;
    flex: 1;
}
.product-price {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--accent);
    margin-bottom: 12px;
}
.product-stock {
    font-size: 0.85rem;
    margin-bottom: 16px;
}
.stock-available {
    color: #059669;
    font-weight: 600;
}
.stock-low {
    color: #d97706;
    font-weight: 600;
}
.stock-out {
    color: #dc2626;
    font-weight: 600;
}
.add-to-cart-form {
    display: flex;
    gap: 8px;
}
.qty-input {
    width: 80px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
}
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 24px;
    font-size: 0.92rem;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.empty-state {
    text-align: center;
    padding: 64px 24px;
    color: var(--muted);
}
.empty-state h3 {
    font-size: 1.4rem;
    margin: 0 0 12px;
    color: var(--text);
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        <a href="cart.php" style="float: right; color: var(--accent); font-weight: 600;">View Cart →</a>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="filters-card">
    <form method="get" action="products.php">
        <div class="filters-grid">
            <div class="form-group">
                <label for="search">Search Products</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    placeholder="Search by name or SKU..."
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="category">Category</label>
                <select id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['topcat'], ENT_QUOTES, 'UTF-8') ?>"
                            <?= $category === $cat['topcat'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['topcat_name'] ?? $cat['topcat'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input
                        type="checkbox"
                        id="in_stock"
                        name="in_stock"
                        value="1"
                        <?= $inStock ? 'checked' : '' ?>
                    >
                    <label for="in_stock">In Stock Only</label>
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </div>
    </form>
</div>

<!-- Products Grid -->
<?php if (count($products) > 0): ?>
    <div class="products-grid">
        <?php foreach ($products as $product): ?>
            <?php
            $productId = (int)$product['id'];
            $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
            $sku = htmlspecialchars($product['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($product['topcat_name'] ?? $product['topcat'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8');
            $price = (float)$product['sale_price_usd'];
            $unit = htmlspecialchars($product['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
            $qtyOnHand = (float)$product['quantity_on_hand'];
            $minQty = (float)$product['min_quantity'];
            $description = htmlspecialchars($product['description'] ?? 'No description available.', ENT_QUOTES, 'UTF-8');

            // Stock status
            if ($qtyOnHand <= 0) {
                $stockClass = 'stock-out';
                $stockText = 'Out of Stock';
                $canOrder = false;
            } elseif ($qtyOnHand < $minQty * 5) {
                $stockClass = 'stock-low';
                $stockText = 'Low Stock (' . number_format($qtyOnHand, 2) . ' ' . $unit . ')';
                $canOrder = true;
            } else {
                $stockClass = 'stock-available';
                $stockText = 'In Stock (' . number_format($qtyOnHand, 2) . ' ' . $unit . ')';
                $canOrder = true;
            }
            ?>
            <div class="product-card">
                <div class="product-header">
                    <h3><?= $itemName ?></h3>
                    <div class="sku">SKU: <?= $sku ?></div>
                </div>
                <div class="product-category"><?= $category ?></div>
                <div class="product-description"><?= $description ?></div>
                <div class="product-price">$<?= number_format($price, 2) ?></div>
                <div class="product-stock <?= $stockClass ?>">
                    <?= $stockText ?>
                </div>
                <?php if ($canOrder): ?>
                    <form method="post" action="products.php<?= $search || $category || $inStock ? '?' . http_build_query($_GET) : '' ?>" class="add-to-cart-form">
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                        <input
                            type="number"
                            name="quantity"
                            class="qty-input"
                            value="<?= number_format($minQty, 2, '.', '') ?>"
                            min="<?= number_format($minQty, 2, '.', '') ?>"
                            step="<?= $minQty < 1 ? '0.01' : '1' ?>"
                            required
                        >
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            Add to Cart
                        </button>
                    </form>
                    <div style="margin-top: 8px; font-size: 0.8rem; color: var(--muted);">
                        Min. qty: <?= number_format($minQty, 2) ?> <?= $unit ?>
                    </div>
                <?php else: ?>
                    <button class="btn" disabled style="width: 100%; opacity: 0.5; cursor: not-allowed;">
                        Out of Stock
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="empty-state">
            <h3>No Products Found</h3>
            <p>Try adjusting your search or filters.</p>
            <a href="products.php" class="btn btn-primary">Clear Filters</a>
        </div>
    </div>
<?php endif; ?>

<?php

customer_portal_render_layout_end();
