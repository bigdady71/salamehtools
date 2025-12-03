<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

$orderId = (int)($_GET['id'] ?? 0);
$placed = isset($_GET['placed']) && $_GET['placed'] === '1';

if ($orderId === 0) {
    header('Location: orders.php');
    exit;
}

// Fetch order details
$orderStmt = $pdo->prepare("
    SELECT
        o.id,
        o.created_at,
        o.order_type,
        o.status,
        o.total_usd,
        o.total_lbp,
        o.notes,
        o.created_at,
        o.updated_at
    FROM orders o
    WHERE o.id = ? AND o.customer_id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId, $customerId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Fetch order items
$itemsStmt = $pdo->prepare("
    SELECT
        oi.id,
        oi.quantity,
        oi.unit_price_usd,
        oi.discount_percent,
        p.id as product_id,
        p.sku,
        p.item_name,
        p.unit
    FROM order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate subtotal for each item
foreach ($items as &$item) {
    $item['subtotal_usd'] = (float)$item['quantity'] * (float)$item['unit_price_usd'];
    if ((float)$item['discount_percent'] > 0) {
        $item['subtotal_usd'] *= (1 - (float)$item['discount_percent'] / 100);
    }
}
unset($item);

// Fetch invoice if exists
$invoiceStmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.issued_at,
        i.due_date,
        i.status,
        i.total_usd as total_amount_usd
    FROM invoices i
    WHERE i.order_id = ?
    LIMIT 1
");
$invoiceStmt->execute([$orderId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

$title = 'Order #' . $orderId . ' - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Order #' . $orderId,
    'subtitle' => 'View order details and tracking information',
    'customer' => $customer,
    'active' => 'orders',
    'actions' => [
        ['label' => '← Back to Orders', 'href' => 'orders.php'],
    ]
]);

$statusClass = 'badge-' . strtolower($order['status']);
$orderType = htmlspecialchars($order['order_type'], ENT_QUOTES, 'UTF-8');
$orderTypeDisplay = match($orderType) {
    'customer_order' => 'Online Order',
    'van_stock_sale' => 'Van Sale',
    'company_order' => 'Company Order',
    default => ucwords(str_replace('_', ' ', $orderType))
};

?>

<style>
.order-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
}
@media (max-width: 900px) {
    .order-grid {
        grid-template-columns: 1fr;
    }
}
.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.badge-pending {
    background: #fef3c7;
    color: #92400e;
}
.badge-approved {
    background: #dbeafe;
    color: #1e40af;
}
.badge-processing {
    background: #e0e7ff;
    color: #3730a3;
}
.badge-shipped {
    background: #d1fae5;
    color: #065f46;
}
.badge-delivered {
    background: #d1fae5;
    color: #065f46;
}
.badge-cancelled {
    background: #fee2e2;
    color: #991b1b;
}
.status-timeline {
    margin: 24px 0;
    padding: 0;
    list-style: none;
}
.status-timeline li {
    display: flex;
    align-items: center;
    padding: 16px 0;
    border-left: 3px solid var(--border);
    padding-left: 24px;
    position: relative;
}
.status-timeline li.active {
    border-left-color: var(--accent);
}
.status-timeline li.completed {
    border-left-color: var(--accent);
    opacity: 0.7;
}
.status-timeline li::before {
    content: '';
    position: absolute;
    left: -8px;
    width: 14px;
    height: 14px;
    background: var(--border);
    border-radius: 50%;
}
.status-timeline li.active::before {
    background: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
}
.status-timeline li.completed::before {
    background: var(--accent);
}
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.items-table th {
    text-align: left;
    padding: 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.items-table td {
    padding: 16px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid var(--border);
}
.info-row:last-child {
    border-bottom: none;
}
.info-row .label {
    font-weight: 600;
    color: var(--text);
}
.info-row .value {
    color: var(--muted);
    text-align: right;
}
.total-row {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--accent);
    padding-top: 18px;
    margin-top: 12px;
    border-top: 2px solid var(--border);
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
</style>

<?php if ($placed): ?>
    <div class="alert alert-success">
        <strong>✓ Order Placed Successfully!</strong><br>
        Your order has been submitted and is pending approval from your sales representative. You will be notified once it's reviewed.
    </div>
<?php endif; ?>

<div class="order-grid">
    <!-- Main Content -->
    <div>
        <!-- Order Information -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0 0 8px;">Order #<?= $orderId ?></h2>
                    <p style="margin: 0; color: var(--muted); font-size: 0.9rem;">
                        Placed on <?= date('F d, Y \a\t g:i A', strtotime($order['created_at'])) ?>
                    </p>
                </div>
                <span class="badge <?= $statusClass ?>">
                    <?= ucfirst(htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8')) ?>
                </span>
            </div>

            <!-- Status Timeline -->
            <h3 style="margin: 24px 0 0; font-size: 1.1rem;">Order Timeline</h3>
            <ul class="status-timeline">
                <?php
                $currentStatus = $order['status'];
                $statusFlow = ['pending', 'approved', 'processing', 'shipped', 'delivered'];
                $currentIndex = array_search($currentStatus, $statusFlow);
                if ($currentIndex === false) $currentIndex = -1;

                $statusLabels = [
                    'pending' => 'Order Received - Awaiting Approval',
                    'approved' => 'Order Approved',
                    'processing' => 'Processing Order',
                    'shipped' => 'Shipped',
                    'delivered' => 'Delivered'
                ];

                foreach ($statusFlow as $index => $status):
                    $class = '';
                    if ($index < $currentIndex) {
                        $class = 'completed';
                    } elseif ($index === $currentIndex) {
                        $class = 'active';
                    }
                ?>
                    <li class="<?= $class ?>">
                        <div>
                            <strong><?= $statusLabels[$status] ?></strong>
                            <?php if ($class === 'active'): ?>
                                <span style="display: block; font-size: 0.85rem; color: var(--muted); margin-top: 4px;">
                                    Current Status
                                </span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>

                <?php if ($currentStatus === 'cancelled'): ?>
                    <li class="active" style="border-left-color: #dc2626;">
                        <div>
                            <strong style="color: #dc2626;">Order Cancelled</strong>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Order Items -->
        <div class="card">
            <h2>Order Items</h2>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Unit Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemName = htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8');
                        $sku = htmlspecialchars($item['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                        $unit = htmlspecialchars($item['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
                        $unitPrice = (float)$item['unit_price_usd'];
                        $quantity = (float)$item['quantity'];
                        $subtotal = (float)$item['subtotal_usd'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= $itemName ?></strong><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">SKU: <?= $sku ?></span>
                            </td>
                            <td>
                                $<?= number_format($unitPrice, 2) ?><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">per <?= $unit ?></span>
                            </td>
                            <td><?= number_format($quantity, 2) ?> <?= $unit ?></td>
                            <td><strong style="color: var(--accent);">$<?= number_format($subtotal, 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Order Notes -->
        <?php if ($order['notes']): ?>
            <div class="card" style="margin-top: 24px;">
                <h2>Order Notes</h2>
                <p style="margin: 16px 0 0; color: var(--muted); line-height: 1.6;">
                    <?= nl2br(htmlspecialchars($order['notes'], ENT_QUOTES, 'UTF-8')) ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div>
        <!-- Order Summary -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Order Summary</h2>
            <div style="margin-top: 20px;">
                <div class="info-row">
                    <span class="label">Order Type</span>
                    <span class="value"><?= $orderTypeDisplay ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Items</span>
                    <span class="value"><?= count($items) ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Subtotal</span>
                    <span class="value">$<?= number_format((float)$order['total_usd'], 2) ?></span>
                </div>
                <div class="info-row total-row">
                    <span>Total</span>
                    <span>$<?= number_format((float)$order['total_usd'], 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Invoice Information -->
        <?php if ($invoice): ?>
            <div class="card" style="margin-bottom: 24px;">
                <h2>Invoice</h2>
                <div style="margin-top: 20px;">
                    <div class="info-row">
                        <span class="label">Invoice #</span>
                        <span class="value">
                            <a href="invoice_details.php?id=<?= (int)$invoice['id'] ?>">
                                <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="label">Status</span>
                        <span class="value">
                            <span class="badge badge-<?= strtolower($invoice['status']) ?>">
                                <?= ucfirst(htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8')) ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="label">Amount</span>
                        <span class="value">$<?= number_format((float)$invoice['total_amount_usd'], 2) ?></span>
                    </div>
                    <?php if ($invoice['due_date']): ?>
                        <div class="info-row">
                            <span class="label">Due Date</span>
                            <span class="value"><?= date('M d, Y', strtotime($invoice['due_date'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="invoice_details.php?id=<?= (int)$invoice['id'] ?>" class="btn btn-primary" style="width: 100%; text-align: center; margin-top: 16px;">
                    View Invoice
                </a>
            </div>
        <?php else: ?>
            <div class="card" style="margin-bottom: 24px;">
                <h2>Invoice</h2>
                <p style="margin: 16px 0 0; color: var(--muted); font-size: 0.9rem;">
                    An invoice will be created once your order is approved and processed.
                </p>
            </div>
        <?php endif; ?>

        <!-- Delivery Information -->
        <div class="card">
            <h2>Delivery Information</h2>
            <div style="margin-top: 20px;">
                <div class="info-row">
                    <span class="label">Customer</span>
                    <span class="value"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Phone</span>
                    <span class="value"><?= htmlspecialchars($customer['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Location</span>
                    <span class="value"><?= htmlspecialchars($customer['location'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php

customer_portal_render_layout_end();
