<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';

use SalamehTools\Middleware\RBACMiddleware;

require_login();
RBACMiddleware::requireRole('sales_rep', 'Access denied. Sales representatives only.');

$user = auth_user();
$pdo = db();
$title = 'Create Order';

// Get sales rep's customers
$myCustomers = $pdo->prepare("
    SELECT id, name, phone, location
    FROM customers
    WHERE assigned_sales_rep_id = :rep_id AND is_active = 1
    ORDER BY name ASC
");
$myCustomers->execute([':rep_id' => $user['id']]);
$customers = $myCustomers->fetchAll(PDO::FETCH_ASSOC);

// Get active products
$products = $pdo->query("
    SELECT id, sku, item_name, second_name, sale_price_usd, wholesale_price_usd, quantity_on_hand
    FROM products
    WHERE is_active = 1
    ORDER BY item_name ASC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// Get current USD/LBP exchange rate (hardcoded for now, should come from exchange_rates table)
$defaultRate = 89500;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    try {
        $pdo->beginTransaction();

        $customerId = (int)($_POST['customer_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $notes = trim($_POST['notes'] ?? '');

        if (empty($customerId)) {
            throw new Exception('Please select a customer');
        }

        if (empty($items)) {
            throw new Exception('Please add at least one product');
        }

        // Generate order number
        $stmt = $pdo->query("SELECT MAX(id) as max_id FROM orders");
        $maxId = $stmt->fetchColumn() ?: 0;
        $orderNumber = 'ORD-' . str_pad($maxId + 1, 6, '0', STR_PAD_LEFT);

        // Calculate totals
        $totalUsd = 0;
        $totalLbp = 0;

        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $totalUsd += $qty * $price;
        }

        // Create order
        $insertOrder = $pdo->prepare("
            INSERT INTO orders (order_number, customer_id, sales_rep_id, total_usd, total_lbp, created_at, updated_at)
            VALUES (:order_number, :customer_id, :sales_rep_id, :total_usd, :total_lbp, NOW(), NOW())
        ");
        $insertOrder->execute([
            ':order_number' => $orderNumber,
            ':customer_id' => $customerId,
            ':sales_rep_id' => $user['id'],
            ':total_usd' => $totalUsd,
            ':total_lbp' => $totalLbp
        ]);

        $orderId = (int)$pdo->lastInsertId();

        // Insert order items
        $insertItem = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price_usd, unit_price_lbp)
            VALUES (:order_id, :product_id, :quantity, :unit_price_usd, :unit_price_lbp)
        ");

        foreach ($items as $item) {
            $insertItem->execute([
                ':order_id' => $orderId,
                ':product_id' => (int)$item['product_id'],
                ':quantity' => (float)$item['quantity'],
                ':unit_price_usd' => (float)$item['price'],
                ':unit_price_lbp' => 0
            ]);
        }

        // Create initial status event
        $insertStatus = $pdo->prepare("
            INSERT INTO order_status_events (order_id, status, actor_user_id, created_at)
            VALUES (:order_id, 'pending', :actor_id, NOW())
        ");
        $insertStatus->execute([
            ':order_id' => $orderId,
            ':actor_id' => $user['id']
        ]);

        $pdo->commit();

        flash('success', "Order {$orderNumber} created successfully!");
        header('Location: dashboard.php');
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Failed to create order: ' . $e->getMessage());
    }
}

admin_render_layout_start(['title' => $title, 'user' => $user]);
?>

<style>
.form-container {
    max-width: 1200px;
    margin: 0 auto;
}
.section {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}
.section h2 {
    margin: 0 0 20px 0;
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #475569;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.btn {
    padding: 10px 20px;
    border-radius: 6px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}
.btn-primary {
    background: #3b82f6;
    color: white;
}
.btn-primary:hover {
    background: #2563eb;
}
.btn-secondary {
    background: #64748b;
    color: white;
}
.btn-success {
    background: #10b981;
    color: white;
}
.btn-danger {
    background: #ef4444;
    color: white;
}
.product-search {
    position: relative;
    margin-bottom: 20px;
}
.product-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 10;
    display: none;
}
.product-item {
    padding: 12px;
    cursor: pointer;
    border-bottom: 1px solid #e2e8f0;
}
.product-item:hover {
    background: #f8fafc;
}
.product-item:last-child {
    border-bottom: none;
}
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.items-table th {
    text-align: left;
    padding: 12px;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}
.items-table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
}
.items-table input {
    width: 80px;
    padding: 6px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
}
.order-summary {
    background: #f8fafc;
    padding: 20px;
    border-radius: 6px;
    margin-top: 20px;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    font-size: 14px;
}
.summary-row.total {
    border-top: 2px solid #cbd5e1;
    margin-top: 8px;
    padding-top: 12px;
    font-size: 18px;
    font-weight: 700;
}
.empty-state {
    text-align: center;
    padding: 40px;
    color: #64748b;
}
</style>

<div class="form-container">
    <h1>Create New Order</h1>
    <p style="color: #64748b; margin-bottom: 30px;">Fill in the order details below</p>

    <form method="POST" id="orderForm">
        <input type="hidden" name="action" value="create_order">
        <input type="hidden" name="items" id="itemsInput">

        <!-- Customer Selection -->
        <div class="section">
            <h2>Customer Information</h2>
            <div class="form-group">
                <label for="customer_id">Select Customer *</label>
                <select name="customer_id" id="customer_id" class="form-control" required>
                    <option value="">-- Choose a customer --</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>">
                            <?= htmlspecialchars($customer['name']) ?>
                            <?php if ($customer['location']): ?>
                                - <?= htmlspecialchars($customer['location']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Products -->
        <div class="section">
            <h2>Order Items</h2>

            <!-- Product Search -->
            <div class="product-search">
                <input type="text" id="productSearch" class="form-control" placeholder="Search products by name or SKU...">
                <div class="product-results" id="productResults"></div>
            </div>

            <!-- Items Table -->
            <table class="items-table" id="itemsTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Stock</th>
                        <th>Price</th>
                        <th>Qty</th>
                        <th>Subtotal</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="itemsBody">
                    <tr class="empty-state">
                        <td colspan="7">No items added. Search and add products above.</td>
                    </tr>
                </tbody>
            </table>

            <!-- Order Summary -->
            <div class="order-summary">
                <div class="summary-row">
                    <span>Items:</span>
                    <span id="itemCount">0</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="orderTotal">$0.00</span>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="section">
            <h2>Additional Notes (Optional)</h2>
            <div class="form-group">
                <textarea name="notes" class="form-control" rows="4" placeholder="Any special instructions or notes..."></textarea>
            </div>
        </div>

        <!-- Actions -->
        <div style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary">Create Order</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
// Product data
const products = <?= json_encode($products) ?>;
let orderItems = [];

// Product search
const searchInput = document.getElementById('productSearch');
const resultsDiv = document.getElementById('productResults');

searchInput.addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();

    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }

    const filtered = products.filter(p =>
        p.item_name.toLowerCase().includes(query) ||
        (p.second_name && p.second_name.toLowerCase().includes(query)) ||
        p.sku.toLowerCase().includes(query)
    ).slice(0, 10);

    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="product-item">No products found</div>';
        resultsDiv.style.display = 'block';
        return;
    }

    resultsDiv.innerHTML = filtered.map(p => `
        <div class="product-item" onclick="addProduct(${p.id})">
            <strong>${p.item_name}</strong>
            ${p.second_name ? `<br><small>${p.second_name}</small>` : ''}
            <br>
            <small style="color: #64748b;">
                SKU: ${p.sku} |
                Stock: ${p.quantity_on_hand} |
                Price: $${parseFloat(p.sale_price_usd).toFixed(2)}
            </small>
        </div>
    `).join('');

    resultsDiv.style.display = 'block';
});

// Close results when clicking outside
document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
        resultsDiv.style.display = 'none';
    }
});

// Add product to order
function addProduct(productId) {
    const product = products.find(p => p.id === productId);
    if (!product) return;

    // Check if already added
    if (orderItems.find(item => item.product_id === productId)) {
        alert('Product already added to order');
        return;
    }

    orderItems.push({
        product_id: product.id,
        name: product.item_name,
        sku: product.sku,
        stock: product.quantity_on_hand,
        price: parseFloat(product.sale_price_usd),
        quantity: 1
    });

    renderItems();
    searchInput.value = '';
    resultsDiv.style.display = 'none';
}

// Remove item
function removeItem(index) {
    orderItems.splice(index, 1);
    renderItems();
}

// Update quantity
function updateQuantity(index, qty) {
    qty = parseFloat(qty) || 0;
    if (qty <= 0) {
        removeItem(index);
        return;
    }
    orderItems[index].quantity = qty;
    renderItems();
}

// Render items table
function renderItems() {
    const tbody = document.getElementById('itemsBody');

    if (orderItems.length === 0) {
        tbody.innerHTML = '<tr class="empty-state"><td colspan="7">No items added. Search and add products above.</td></tr>';
        updateSummary();
        return;
    }

    tbody.innerHTML = orderItems.map((item, index) => {
        const subtotal = item.quantity * item.price;
        return `
            <tr>
                <td>${item.name}</td>
                <td>${item.sku}</td>
                <td>${item.stock}</td>
                <td>$${item.price.toFixed(2)}</td>
                <td>
                    <input type="number"
                           value="${item.quantity}"
                           min="0.1"
                           step="0.1"
                           onchange="updateQuantity(${index}, this.value)">
                </td>
                <td>$${subtotal.toFixed(2)}</td>
                <td>
                    <button type="button" class="btn btn-danger" style="padding: 4px 8px; font-size: 12px;" onclick="removeItem(${index})">
                        Remove
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    updateSummary();
}

// Update order summary
function updateSummary() {
    const itemCount = orderItems.length;
    const total = orderItems.reduce((sum, item) => sum + (item.quantity * item.price), 0);

    document.getElementById('itemCount').textContent = itemCount;
    document.getElementById('orderTotal').textContent = '$' + total.toFixed(2);
}

// Form submission
document.getElementById('orderForm').addEventListener('submit', function(e) {
    if (orderItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one product to the order');
        return;
    }

    // Set items as JSON
    document.getElementById('itemsInput').value = JSON.stringify(orderItems);
});

// Initial render
renderItems();
</script>

<?php admin_render_layout_end(); ?>
