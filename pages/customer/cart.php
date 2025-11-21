<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

$success = null;
$error = null;

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_quantity') {
        $cartId = (int)($_POST['cart_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);

        if ($cartId > 0 && $quantity > 0) {
            // Verify cart item belongs to customer
            $cartStmt = $pdo->prepare("
                SELECT cc.id, p.min_quantity, p.item_name
                FROM customer_cart cc
                INNER JOIN products p ON p.id = cc.product_id
                WHERE cc.id = ? AND cc.customer_id = ?
            ");
            $cartStmt->execute([$cartId, $customerId]);
            $cartItem = $cartStmt->fetch(PDO::FETCH_ASSOC);

            if ($cartItem) {
                $minQty = (float)$cartItem['min_quantity'];
                if ($quantity < $minQty) {
                    $error = 'Minimum quantity for ' . $cartItem['item_name'] . ' is ' . number_format($minQty, 2);
                } else {
                    $updateStmt = $pdo->prepare("UPDATE customer_cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$quantity, $cartId]);
                    $success = 'Cart updated successfully!';
                }
            }
        }
    } elseif ($action === 'remove_item') {
        $cartId = (int)($_POST['cart_id'] ?? 0);

        if ($cartId > 0) {
            $deleteStmt = $pdo->prepare("DELETE FROM customer_cart WHERE id = ? AND customer_id = ?");
            $deleteStmt->execute([$cartId, $customerId]);
            $success = 'Item removed from cart.';
        }
    } elseif ($action === 'clear_cart') {
        $deleteStmt = $pdo->prepare("DELETE FROM customer_cart WHERE customer_id = ?");
        $deleteStmt->execute([$customerId]);
        $success = 'Cart cleared successfully!';
    }
}

// Fetch cart items
$cartQuery = "
    SELECT
        cc.id as cart_id,
        cc.quantity,
        p.id as product_id,
        p.sku,
        p.item_name,
        p.unit,
        p.sale_price_usd,
        p.min_quantity,
        p.quantity_on_hand
    FROM customer_cart cc
    INNER JOIN products p ON p.id = cc.product_id
    WHERE cc.customer_id = ?
    ORDER BY cc.added_at DESC
";

$cartStmt = $pdo->prepare($cartQuery);
$cartStmt->execute([$customerId]);
$cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$subtotal = 0;
$totalItems = 0;
foreach ($cartItems as $item) {
    $qty = (float)$item['quantity'];
    $price = (float)$item['sale_price_usd'];
    $subtotal += $qty * $price;
    $totalItems += 1;
}

$title = 'Shopping Cart - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Shopping Cart',
    'subtitle' => 'Review and modify your cart items',
    'customer' => $customer,
    'active' => 'cart',
    'actions' => count($cartItems) > 0 ? [
        ['label' => '🛍️ Continue Shopping', 'href' => 'products.php'],
        ['label' => '✓ Proceed to Checkout', 'href' => 'checkout.php', 'variant' => 'primary'],
    ] : []
]);

?>

<style>
.cart-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
}
@media (max-width: 900px) {
    .cart-grid {
        grid-template-columns: 1fr;
    }
}
.cart-table {
    width: 100%;
    border-collapse: collapse;
}
.cart-table th {
    text-align: left;
    padding: 16px 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.cart-table td {
    padding: 20px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.product-info h4 {
    margin: 0 0 4px;
    font-size: 1.05rem;
    color: var(--text);
}
.product-info .sku {
    font-size: 0.8rem;
    color: var(--muted);
}
.qty-input {
    width: 100px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
}
.update-btn {
    margin-left: 8px;
    padding: 8px 12px;
    background: var(--accent);
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.update-btn:hover {
    background: #059669;
}
.remove-btn {
    padding: 6px 12px;
    background: transparent;
    color: #dc2626;
    border: 1px solid #fecaca;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.remove-btn:hover {
    background: #fee2e2;
}
.summary-card {
    background: var(--bg-panel-alt);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    height: fit-content;
    position: sticky;
    top: 24px;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.summary-row:last-child {
    border-bottom: none;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--accent);
    padding-top: 16px;
    margin-top: 8px;
    border-top: 2px solid var(--border);
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
.stock-warning {
    display: inline-block;
    padding: 4px 10px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 8px;
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if (count($cartItems) > 0): ?>
    <div class="cart-grid">
        <!-- Cart Items -->
        <div class="card">
            <h2>Cart Items (<?= $totalItems ?>)</h2>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Unit Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cartItems as $item): ?>
                        <?php
                        $cartId = (int)$item['cart_id'];
                        $productId = (int)$item['product_id'];
                        $itemName = htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8');
                        $sku = htmlspecialchars($item['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                        $unit = htmlspecialchars($item['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
                        $price = (float)$item['sale_price_usd'];
                        $quantity = (float)$item['quantity'];
                        $minQty = (float)$item['min_quantity'];
                        $qtyOnHand = (float)$item['quantity_on_hand'];
                        $itemSubtotal = $price * $quantity;

                        $stockWarning = $quantity > $qtyOnHand ? 'Insufficient stock' : '';
                        ?>
                        <tr>
                            <td>
                                <div class="product-info">
                                    <h4><?= $itemName ?></h4>
                                    <div class="sku">SKU: <?= $sku ?></div>
                                    <?php if ($stockWarning): ?>
                                        <span class="stock-warning"><?= $stockWarning ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong>$<?= number_format($price, 2) ?></strong><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">per <?= $unit ?></span>
                            </td>
                            <td>
                                <form method="post" action="cart.php" style="display: inline-flex; align-items: center;">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="hidden" name="cart_id" value="<?= $cartId ?>">
                                    <input
                                        type="number"
                                        name="quantity"
                                        class="qty-input"
                                        value="<?= number_format($quantity, 2, '.', '') ?>"
                                        min="<?= number_format($minQty, 2, '.', '') ?>"
                                        step="<?= $minQty < 1 ? '0.01' : '1' ?>"
                                        required
                                    >
                                    <button type="submit" class="update-btn">Update</button>
                                </form>
                                <div style="margin-top: 4px; font-size: 0.75rem; color: var(--muted);">
                                    Min: <?= number_format($minQty, 2) ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color: var(--accent); font-size: 1.1rem;">
                                    $<?= number_format($itemSubtotal, 2) ?>
                                </strong>
                            </td>
                            <td>
                                <form method="post" action="cart.php" style="display: inline;">
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="cart_id" value="<?= $cartId ?>">
                                    <button
                                        type="submit"
                                        class="remove-btn"
                                        onclick="return confirm('Remove this item from cart?');"
                                    >
                                        Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 24px; text-align: right;">
                <form method="post" action="cart.php" style="display: inline;">
                    <input type="hidden" name="action" value="clear_cart">
                    <button
                        type="submit"
                        class="btn"
                        onclick="return confirm('Clear all items from cart?');"
                        style="background: transparent; color: #dc2626; border-color: #fecaca;"
                    >
                        Clear Cart
                    </button>
                </form>
            </div>
        </div>

        <!-- Order Summary -->
        <div class="summary-card">
            <h2 style="margin: 0 0 20px;">Order Summary</h2>
            <div class="summary-row">
                <span>Items (<?= $totalItems ?>)</span>
                <span><?= $totalItems ?></span>
            </div>
            <div class="summary-row">
                <span>Subtotal</span>
                <span>$<?= number_format($subtotal, 2) ?></span>
            </div>
            <div class="summary-row">
                <span>Total</span>
                <span>$<?= number_format($subtotal, 2) ?></span>
            </div>

            <a href="checkout.php" class="btn btn-primary" style="width: 100%; text-align: center; margin-top: 20px; padding: 14px;">
                Proceed to Checkout
            </a>
            <a href="products.php" class="btn" style="width: 100%; text-align: center; margin-top: 12px;">
                Continue Shopping
            </a>

            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 0.85rem; color: var(--muted);">
                <p style="margin: 0;">
                    <strong>Note:</strong> Your order will be reviewed and approved by your sales representative before processing.
                </p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="empty-state">
            <h3>🛒 Your Cart is Empty</h3>
            <p>Start adding products to your cart to place an order.</p>
            <a href="products.php" class="btn btn-primary">Browse Products</a>
        </div>
    </div>
<?php endif; ?>

<?php

customer_portal_render_layout_end();
