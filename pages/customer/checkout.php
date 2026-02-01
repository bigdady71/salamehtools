<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

$success = null;
$error = null;

// Fetch cart items
$cartQuery = "
    SELECT
        cc.id as cart_id,
        cc.quantity,
        p.id as product_id,
        p.sku,
        p.item_name,
        p.unit,
        p.wholesale_price_usd,
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

// Redirect if cart is empty
if (count($cartItems) === 0) {
    header('Location: cart.php');
    exit;
}

// Calculate totals and check stock
$subtotal = 0;
$hasStockIssues = false;
$stockIssueItems = [];
foreach ($cartItems as $item) {
    $qty = (float)$item['quantity'];
    $price = (float)$item['wholesale_price_usd'];
    $qtyOnHand = (float)$item['quantity_on_hand'];

    $subtotal += $qty * $price;

    if ($qty > $qtyOnHand) {
        $hasStockIssues = true;
        $stockIssueItems[] = [
            'name' => $item['item_name'],
            'requested' => $qty,
            'available' => $qtyOnHand,
            'unit' => $item['unit']
        ];
    }
}

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    if ($hasStockIssues) {
        $errorDetails = '<strong>Stock issues detected:</strong><ul style="margin: 8px 0 0 0; padding-left: 20px;">';
        foreach ($stockIssueItems as $issue) {
            $errorDetails .= '<li>' . htmlspecialchars($issue['name'], ENT_QUOTES, 'UTF-8') .
                ' - Requested: ' . number_format($issue['requested'], 2) . ' ' . htmlspecialchars($issue['unit'], ENT_QUOTES, 'UTF-8') .
                ', Available: ' . number_format($issue['available'], 2) . ' ' . htmlspecialchars($issue['unit'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $errorDetails .= '</ul><a href="cart.php" style="color: inherit; text-decoration: underline;">Go back to cart to adjust quantities</a>';
        $error = $errorDetails;
    } else {
        $notes = trim($_POST['notes'] ?? '');

        try {
            $pdo->beginTransaction();

            // Get customer's assigned sales rep (if any)
            $salesRepStmt = $pdo->prepare("SELECT assigned_sales_rep_id FROM customers WHERE id = ?");
            $salesRepStmt->execute([$customerId]);
            $assignedSalesRepId = $salesRepStmt->fetchColumn() ?: null;

            // Create order (status: pending for approval)
            // Assign to customer's sales rep so they can see and deliver it
            $insertOrderStmt = $pdo->prepare("
                INSERT INTO orders (
                    customer_id,
                    sales_rep_id,
                    order_type,
                    status,
                    total_usd,
                    total_lbp,
                    notes,
                    created_at
                ) VALUES (?, ?, 'customer_order', 'pending', ?, 0, ?, NOW())
            ");
            $insertOrderStmt->execute([$customerId, $assignedSalesRepId, $subtotal, $notes]);
            $orderId = (int)$pdo->lastInsertId();

            // Insert order items
            $insertItemStmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id,
                    product_id,
                    quantity,
                    unit_price_usd,
                    unit_price_lbp,
                    discount_percent
                ) VALUES (?, ?, ?, ?, 0, 0)
            ");

            foreach ($cartItems as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                $unitPrice = (float)$item['wholesale_price_usd'];

                $insertItemStmt->execute([
                    $orderId,
                    $productId,
                    $quantity,
                    $unitPrice
                ]);
            }

            // Clear customer cart
            $clearCartStmt = $pdo->prepare("DELETE FROM customer_cart WHERE customer_id = ?");
            $clearCartStmt->execute([$customerId]);

            $pdo->commit();

            // Redirect to order confirmation
            header('Location: order_details.php?id=' . $orderId . '&placed=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            // Log error for debugging (in production, use proper logging)
            error_log("Order placement failed for customer {$customerId}: " . $e->getMessage());
            $error = 'Failed to place order. Please try again or contact your sales representative. Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}

$title = 'Checkout - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Checkout',
    'subtitle' => 'Review and submit your order',
    'customer' => $customer,
    'active' => 'cart'
]);

?>

<style>
    .checkout-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
    }

    @media (max-width: 900px) {
        .checkout-grid {
            grid-template-columns: 1fr;
        }
    }

    .order-items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .order-items-table th {
        text-align: left;
        padding: 12px;
        border-bottom: 2px solid var(--border);
        font-weight: 600;
        color: var(--text);
        font-size: 0.9rem;
    }

    .order-items-table td {
        padding: 16px 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
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

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 0.92rem;
        color: var(--text);
    }

    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 1rem;
        font-family: inherit;
        resize: vertical;
        min-height: 100px;
    }

    .form-group textarea:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
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

    .alert-warning {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
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

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($hasStockIssues): ?>
    <div class="alert alert-warning">
        <strong>⚠️ Stock Issue:</strong> Some items have insufficient stock. Please return to your cart and adjust
        quantities before proceeding.
    </div>
<?php endif; ?>

<div class="checkout-grid">
    <!-- Order Review -->
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <h2>Review Your Order</h2>
            <table class="order-items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Unit Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cartItems as $item): ?>
                        <?php
                        $itemName = htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8');
                        $sku = htmlspecialchars($item['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                        $unit = htmlspecialchars($item['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
                        $price = (float)$item['wholesale_price_usd'];
                        $quantity = (float)$item['quantity'];
                        $qtyOnHand = (float)$item['quantity_on_hand'];
                        $itemSubtotal = $price * $quantity;

                        $stockWarning = $quantity > $qtyOnHand ? 'Insufficient stock' : '';
                        ?>
                        <tr>
                            <td>
                                <strong><?= $itemName ?></strong><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">SKU: <?= $sku ?></span>
                                <?php if ($stockWarning): ?>
                                    <span class="stock-warning"><?= $stockWarning ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                $<?= number_format($price, 2) ?><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">per <?= $unit ?></span>
                            </td>
                            <td>
                                <?= number_format($quantity, 2) ?> <?= $unit ?>
                            </td>
                            <td>
                                <strong style="color: var(--accent);">$<?= number_format($itemSubtotal, 2) ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Additional Notes -->
        <div class="card">
            <h2>Additional Information</h2>
            <form method="post" action="checkout.php" id="checkoutForm">
                <input type="hidden" name="action" value="place_order">

                <div class="form-group">
                    <label for="notes">Order Notes (Optional)</label>
                    <textarea id="notes" name="notes"
                        placeholder="Add any special instructions or notes for your sales representative..."></textarea>
                </div>

                <div
                    style="padding: 16px; background: var(--bg-panel-alt); border-radius: 10px; border: 1px solid var(--border); margin-bottom: 20px;">
                    <h3 style="margin: 0 0 12px; font-size: 1rem;">Important Information</h3>
                    <ul style="margin: 0; padding-left: 20px; color: var(--muted); line-height: 1.8;">
                        <li>Your order will be submitted to your sales representative for approval</li>
                        <li>You will be notified once your order is reviewed</li>
                        <li>Orders are typically reviewed within 1-2 business days</li>
                        <li>Stock availability will be confirmed during order processing</li>
                    </ul>
                </div>

                <div style="display: flex; gap: 12px;">
                    <a href="cart.php" class="btn">← Back to Cart</a>
                    <button type="submit" class="btn btn-primary" style="flex: 1;"
                        <?= $hasStockIssues ? 'disabled' : '' ?>>
                        Submit Order for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Order Summary -->
    <div>
        <div class="card" style="position: sticky; top: 24px;">
            <h2>Order Summary</h2>
            <div style="margin-top: 20px;">
                <div class="summary-row">
                    <span>Items</span>
                    <span><?= count($cartItems) ?></span>
                </div>
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>$<?= number_format($subtotal, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Total</span>
                    <span>$<?= number_format($subtotal, 2) ?></span>
                </div>
            </div>

            <div
                style="margin-top: 24px; padding: 16px; background: var(--bg-panel-alt); border-radius: 10px; border: 1px solid var(--border);">
                <h3 style="margin: 0 0 12px; font-size: 0.95rem; color: var(--text);">Delivery Information</h3>
                <div style="font-size: 0.85rem; color: var(--muted); line-height: 1.6;">
                    <p style="margin: 0 0 8px;">
                        <strong>Location:</strong><br><?= htmlspecialchars($customer['location'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <p style="margin: 0;">
                        <strong>Phone:</strong><br><?= htmlspecialchars($customer['phone'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <?php if ($customer['sales_rep_name']): ?>
                <div
                    style="margin-top: 16px; padding: 16px; background: var(--bg-panel-alt); border-radius: 10px; border: 1px solid var(--border);">
                    <h3 style="margin: 0 0 12px; font-size: 0.95rem; color: var(--text);">Your Sales Rep</h3>
                    <div style="font-size: 0.85rem; color: var(--muted); line-height: 1.6;">
                        <p style="margin: 0 0 4px;">
                            <strong><?= htmlspecialchars($customer['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </p>
                        <?php if ($customer['sales_rep_phone']): ?>
                            <p style="margin: 0;">📞 <?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php

customer_portal_render_layout_end();
