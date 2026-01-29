<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

$pdo = db();

$success = null;
$error = null;

// Handle AJAX favorite removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'remove_favorite') {
        header('Content-Type: application/json');
        $productId = (int)($_POST['product_id'] ?? 0);

        if ($productId > 0) {
            $deleteStmt = $pdo->prepare("DELETE FROM customer_favorites WHERE customer_id = ? AND product_id = ?");
            $deleteStmt->execute([$customerId, $productId]);
            echo json_encode(['success' => true, 'message' => 'Removed from favorites']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
        }
        exit;
    }

    if ($_POST['action'] === 'add_to_cart') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 1);

        if ($productId > 0 && $quantity > 0) {
            $productStmt = $pdo->prepare("SELECT id, item_name, min_quantity FROM products WHERE id = ? AND is_active = 1");
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if ($product) {
                $minQuantity = (float)$product['min_quantity'];
                if ($quantity < $minQuantity) {
                    $error = 'Minimum quantity for this product is ' . number_format($minQuantity, 2);
                } else {
                    $cartCheckStmt = $pdo->prepare("SELECT id, quantity FROM customer_cart WHERE customer_id = ? AND product_id = ?");
                    $cartCheckStmt->execute([$customerId, $productId]);
                    $existingCart = $cartCheckStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingCart) {
                        $newQuantity = (float)$existingCart['quantity'] + $quantity;
                        $updateStmt = $pdo->prepare("UPDATE customer_cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
                        $updateStmt->execute([$newQuantity, $existingCart['id']]);
                        $success = 'Updated quantity in cart!';
                    } else {
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
}

// Get favorite products
$favoritesQuery = "
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.second_name,
        p.topcat,
        p.topcat_name,
        p.unit,
        p.wholesale_price_usd,
        p.min_quantity,
        p.quantity_on_hand,
        p.description,
        cf.created_at as favorited_at
    FROM customer_favorites cf
    JOIN products p ON p.id = cf.product_id
    WHERE cf.customer_id = ? AND p.is_active = 1
    ORDER BY cf.created_at DESC
";

$favoritesStmt = $pdo->prepare($favoritesQuery);
$favoritesStmt->execute([$customerId]);
$favorites = $favoritesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get cart count
$cartStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_cart WHERE customer_id = ?");
$cartStmt->execute([$customerId]);
$cartCount = (int)$cartStmt->fetchColumn();

$title = 'My Favorites - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'My Favorites',
    'subtitle' => 'Products you\'ve saved for later',
    'customer' => $customer,
    'active' => 'products',
    'actions' => [
        ['label' => '🛍️ Browse Products', 'href' => 'products.php'],
        ['label' => '🛒 View Cart (' . $cartCount . ')', 'href' => 'cart.php', 'variant' => 'primary'],
    ]
]);
?>

<style>
.favorites-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.favorite-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 0;
    border: 1px solid var(--border);
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    transition: all 0.3s;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.favorite-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
}
.product-image {
    width: 100%;
    height: 180px;
    object-fit: cover;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border-bottom: 1px solid var(--border);
}
.product-image-placeholder {
    width: 100%;
    height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border-bottom: 1px solid var(--border);
    font-size: 3rem;
    color: #9ca3af;
}
.favorite-content {
    padding: 20px;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.favorite-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.favorite-header h3 {
    margin: 0;
    font-size: 1.1rem;
    color: var(--text);
    flex: 1;
}
.remove-btn {
    background: #fee2e2;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.2s;
    margin-left: 8px;
}
.remove-btn:hover {
    background: #fecaca;
    transform: scale(1.1);
}
.favorite-sku {
    font-size: 0.8rem;
    color: var(--muted);
    margin-bottom: 8px;
}
.favorite-price {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--accent);
    margin-bottom: 8px;
}
.stock-status {
    font-size: 0.85rem;
    margin-bottom: 12px;
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
}
.stock-available {
    background: #d1fae5;
    color: #065f46;
}
.stock-low {
    background: #fef3c7;
    color: #92400e;
}
.stock-out {
    background: #fee2e2;
    color: #991b1b;
}
.add-to-cart-form {
    display: flex;
    gap: 8px;
    margin-top: auto;
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
.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
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
.favorited-date {
    font-size: 0.8rem;
    color: var(--muted);
    margin-top: 8px;
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

<?php if (count($favorites) > 0): ?>
    <p style="margin-bottom: 20px; color: var(--muted);">
        You have <?= count($favorites) ?> favorite product<?= count($favorites) !== 1 ? 's' : '' ?> saved.
    </p>

    <div class="favorites-grid">
        <?php foreach ($favorites as $product): ?>
            <?php
            $productId = (int)$product['id'];
            $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
            $sku = htmlspecialchars($product['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $price = (float)$product['wholesale_price_usd'];
            $unit = htmlspecialchars($product['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
            $qtyOnHand = (float)$product['quantity_on_hand'];
            $minQty = (float)$product['min_quantity'];
            $favoritedAt = date('M d, Y', strtotime($product['favorited_at']));

            // Product image path
            $imageExists = false;
            $imagePath = '../../images/products/default.jpg';
            $possibleExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $productSku = $product['sku'] ?? 'default';

            foreach ($possibleExtensions as $ext) {
                $serverPath = __DIR__ . '/../../images/products/' . $productSku . '.' . $ext;
                if (file_exists($serverPath)) {
                    $imagePath = '../../images/products/' . $productSku . '.' . $ext;
                    $imageExists = true;
                    break;
                }
            }

            // Stock status
            if ($qtyOnHand <= 0) {
                $stockClass = 'stock-out';
                $stockText = 'Out of Stock';
                $canOrder = false;
            } elseif ($qtyOnHand < $minQty * 5) {
                $stockClass = 'stock-low';
                $stockText = 'Low Stock';
                $canOrder = true;
            } else {
                $stockClass = 'stock-available';
                $stockText = 'In Stock';
                $canOrder = true;
            }
            ?>
            <div class="favorite-card" data-product-id="<?= $productId ?>">
                <?php if ($imageExists): ?>
                    <img src="<?= $imagePath ?>" alt="<?= $itemName ?>" class="product-image" loading="lazy">
                <?php else: ?>
                    <div class="product-image-placeholder">📦</div>
                <?php endif; ?>

                <div class="favorite-content">
                    <div class="favorite-header">
                        <h3><?= $itemName ?></h3>
                        <button type="button" class="remove-btn" onclick="removeFavorite(<?= $productId ?>, this)" title="Remove from favorites">
                            ✕
                        </button>
                    </div>
                    <div class="favorite-sku">SKU: <?= $sku ?></div>
                    <div class="favorite-price">$<?= number_format($price, 2) ?></div>
                    <div class="stock-status <?= $stockClass ?>"><?= $stockText ?></div>

                    <?php if ($canOrder): ?>
                        <form method="post" class="add-to-cart-form">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                            <input type="number" name="quantity" class="qty-input"
                                   value="<?= max(1, (int)ceil($minQty)) ?>"
                                   min="<?= max(1, (int)ceil($minQty)) ?>" step="1" required>
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                Add to Cart
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn" disabled style="width: 100%; opacity: 0.5;">Out of Stock</button>
                    <?php endif; ?>

                    <div class="favorited-date">Added <?= $favoritedAt ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="empty-state">
            <div style="font-size: 4rem; margin-bottom: 16px;">❤️</div>
            <h3>No Favorites Yet</h3>
            <p>Start adding products to your favorites by clicking the heart icon while browsing.</p>
            <a href="products.php" class="btn btn-primary" style="margin-top: 16px;">Browse Products</a>
        </div>
    </div>
<?php endif; ?>

<script>
function removeFavorite(productId, btn) {
    if (!confirm('Remove this product from your favorites?')) return;

    const card = btn.closest('.favorite-card');
    card.style.opacity = '0.5';

    const formData = new FormData();
    formData.append('action', 'remove_favorite');
    formData.append('product_id', productId);

    fetch('favorites.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            card.style.transition = 'all 0.3s';
            card.style.transform = 'scale(0.8)';
            card.style.opacity = '0';
            setTimeout(() => {
                card.remove();
                // Check if no more favorites
                const remaining = document.querySelectorAll('.favorite-card');
                if (remaining.length === 0) {
                    location.reload();
                }
            }, 300);
        } else {
            card.style.opacity = '1';
            alert(data.error || 'Failed to remove from favorites');
        }
    })
    .catch(error => {
        card.style.opacity = '1';
        console.error('Error:', error);
    });
}
</script>

<?php customer_portal_render_layout_end(); ?>
