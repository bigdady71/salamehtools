<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Get order ID from URL
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    $_SESSION['error'] = 'Invalid order ID';
    header('Location: orders.php');
    exit;
}

// Get order details
$orderStmt = $pdo->prepare("
    SELECT
        o.id,
        o.order_number,
        o.status,
        o.order_type,
        o.created_at,
        o.notes as order_notes,
        o.sales_rep_id,
        c.name as customer_name,
        c.phone as customer_phone,
        c.location as customer_location,
        sr.name as sales_rep_name
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users sr ON sr.id = o.sales_rep_id
    WHERE o.id = ?
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['error'] = 'Order not found';
    header('Location: orders.php');
    exit;
}

// Get order items with picking status
$itemsStmt = $pdo->prepare("
    SELECT
        oi.id,
        oi.quantity as qty_ordered,
        p.id as product_id,
        p.sku,
        p.barcode,
        p.item_name,
        p.description,
        p.unit,
        p.image_url,
        COALESCE(s.qty_on_hand, 0) as qty_in_stock
    FROM order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    LEFT JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = ?
    WHERE oi.order_id = ?
    ORDER BY p.item_name ASC
");
$orderSalesRepId = (int)($order['sales_rep_id'] ?? 0);
$itemsStmt->execute([$orderSalesRepId, $orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Scan & Pick - ' . ($order['order_number'] ?? 'Order #' . $order['id']);

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Barcode Scanning Mode',
    'subtitle' => 'Scan items to verify picking',
    'user' => $user,
    'active' => 'orders',
]);
?>

<style>
.scan-item {
    transition: all 0.3s ease;
}
.scan-item.picked {
    background: #d1fae5 !important;
    border: 2px solid #059669 !important;
}
.scan-item.picked .item-checkbox::before {
    content: '✓';
    color: #059669;
    font-size: 24px;
    font-weight: bold;
}
.scan-input {
    font-size: 1.5rem;
    padding: 16px;
    border: 3px solid var(--primary);
    border-radius: 8px;
    font-family: 'Courier New', monospace;
}
.progress-bar {
    height: 30px;
    background: #e5e7eb;
    border-radius: 15px;
    overflow: hidden;
    margin: 20px 0;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #059669, #10b981);
    transition: width 0.5s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
}
</style>

<div class="card" style="background:#e0e7ff;border-color:#6366f1;margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
        <div>
            <h2 style="margin:0 0 8px;color:#3730a3;">
                <?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <div style="color:#3730a3;font-size:0.95rem;line-height:1.6;">
                <?php if ($order['sales_rep_name']): ?>
                    <strong>Sales Rep:</strong> <?= htmlspecialchars($order['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?> |
                <?php endif; ?>
                <strong>Customer:</strong> <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($order['customer_phone']): ?>
                    | <strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                <br>
                <strong>Date:</strong> <?= date('M d, Y', strtotime($order['created_at'])) ?> |
                <strong>Type:</strong> <?= $order['order_type'] === 'van_stock_sale' ? '🚚 Van Sale' : '🏢 Company Order' ?>
            </div>
            <?php if (!empty($order['order_notes'])): ?>
                <div style="margin-top:10px;padding:10px;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:4px;">
                    <strong style="color:#92400e;font-size:0.9rem;">📝 Order Notes:</strong>
                    <div style="color:#78350f;font-size:0.9rem;margin-top:4px;">
                        <?= nl2br(htmlspecialchars($order['order_notes'], ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <a href="orders.php" class="btn btn-secondary">← Back to Orders</a>
    </div>
</div>

<!-- Scan Input -->
<div class="card" style="background:#f0fdf4;border-color:#10b981;">
    <h3 style="margin:0 0 16px;">📱 Scan Barcode or Enter SKU</h3>
    <input
        type="text"
        id="scanInput"
        class="scan-input"
        placeholder="Scan barcode or type SKU..."
        autofocus
        style="width:100%;"
    >
    <p style="margin:12px 0 0;color:var(--muted);font-size:0.9rem;">
        💡 Tip: Use a barcode scanner or manually type the SKU and press Enter
    </p>
</div>

<!-- Progress -->
<div class="card">
    <h3 style="margin:0 0 12px;">Progress: <span id="pickedCount">0</span> / <?= count($items) ?> Items</h3>
    <div class="progress-bar">
        <div class="progress-fill" id="progressFill" style="width:0%;">
            <span id="progressText">0%</span>
        </div>
    </div>
</div>

<!-- Items List -->
<div class="card">
    <h3 style="margin:0 0 16px;">Items to Pick (<?= count($items) ?>)</h3>

    <div id="itemsList" style="display:flex;flex-direction:column;gap:16px;">
        <?php foreach ($items as $index => $item): ?>
            <?php
            $qtyOrdered = (float)$item['qty_ordered'];
            $qtyInStock = (float)$item['qty_in_stock'];
            $hasStock = $qtyInStock >= $qtyOrdered;
            ?>
            <div class="scan-item"
                 id="item-<?= $index ?>"
                 data-sku="<?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>"
                 data-barcode="<?= htmlspecialchars($item['barcode'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 style="background:white;border:2px solid var(--border);border-radius:8px;padding:16px;">

                <div style="display:grid;grid-template-columns:40px 60px 1fr auto;gap:12px;align-items:center;">
                    <!-- Checkbox -->
                    <div class="item-checkbox" style="width:40px;height:40px;border:2px solid #ccc;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:24px;"></div>

                    <!-- Product Image -->
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                             style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                    <?php else: ?>
                        <div style="width:60px;height:60px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.5rem;">
                            📦
                        </div>
                    <?php endif; ?>

                    <!-- Item Info -->
                    <div>
                        <div style="font-weight:600;font-size:1rem;margin-bottom:4px;">
                            <?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span style="font-family:'Courier New',monospace;font-weight:700;font-size:0.95rem;color:var(--primary);">
                                <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($item['description']): ?>
                                <span style="font-size:0.85rem;color:var(--muted);">
                                    • <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quantity -->
                    <div style="text-align:center;">
                        <div style="font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Qty Needed</div>
                        <div style="font-weight:700;font-size:2rem;color:var(--primary);">
                            <?= number_format($qtyOrdered, 0) ?>
                        </div>
                        <div style="font-size:0.8rem;color:var(--muted);"><?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Complete Button -->
<div class="card" style="background:#f0fdf4;border-color:#10b981;">
    <button id="completeButton" class="btn btn-success" style="width:100%;padding:16px;font-size:1.2rem;" disabled>
        ✓ All Items Picked - Mark Order as Prepared
    </button>
</div>

<script>
// Initialize
const scanInput = document.getElementById('scanInput');
const itemsList = document.getElementById('itemsList');
const items = document.querySelectorAll('.scan-item');
const pickedCountEl = document.getElementById('pickedCount');
const progressFill = document.getElementById('progressFill');
const progressText = document.getElementById('progressText');
const completeButton = document.getElementById('completeButton');

let pickedCount = 0;
const totalItems = <?= count($items) ?>;
const pickedItems = new Set();

// Sound effects (optional)
const successSound = () => {
    const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBCx+zPLTgjMGHm+98+GUPwoUXrTp66hVFApGn+DyvmwhBCx+zPLTgjMGHm+98+GUPwoUXrTp66hVFApGn+DyvmwhBCx+zPLTgjMGHm+98+GUPwoUXrTp66hVFApGn+DyvmwhBCx+zPLTgjMGHm+98+GUPw==');
    audio.play().catch(() => {}); // Ignore errors if audio doesn't play
};

// Update progress
function updateProgress() {
    const percentage = Math.round((pickedCount / totalItems) * 100);
    progressFill.style.width = percentage + '%';
    progressText.textContent = percentage + '%';
    pickedCountEl.textContent = pickedCount;

    if (pickedCount === totalItems) {
        completeButton.disabled = false;
        completeButton.style.animation = 'pulse 1s ease-in-out infinite';
        // Auto-scroll to complete button
        completeButton.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Mark item as picked
function markItemPicked(itemElement) {
    if (pickedItems.has(itemElement.id)) {
        return; // Already picked
    }

    itemElement.classList.add('picked');
    pickedItems.add(itemElement.id);
    pickedCount++;
    updateProgress();
    successSound();

    // Scroll to next unpicked item
    const nextItem = document.querySelector('.scan-item:not(.picked)');
    if (nextItem) {
        nextItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Handle scan input
scanInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        const scannedValue = this.value.trim().toUpperCase();

        if (!scannedValue) {
            return;
        }

        // Find matching item by SKU or barcode
        let found = false;
        items.forEach(item => {
            const sku = item.dataset.sku.toUpperCase();
            const barcode = (item.dataset.barcode || '').toUpperCase();

            if (sku === scannedValue || barcode === scannedValue) {
                markItemPicked(item);
                found = true;
            }
        });

        if (found) {
            // Visual feedback - green flash
            this.style.borderColor = '#10b981';
            this.style.background = '#d1fae5';
            setTimeout(() => {
                this.style.borderColor = '';
                this.style.background = '';
            }, 300);
        } else {
            // Visual feedback - red flash
            this.style.borderColor = '#dc2626';
            this.style.background = '#fee2e2';
            setTimeout(() => {
                this.style.borderColor = '';
                this.style.background = '';
            }, 300);
            alert('❌ Item not found: ' + scannedValue);
        }

        // Clear input
        this.value = '';
    }
});

// Complete order
completeButton.addEventListener('click', function() {
    if (pickedCount === totalItems) {
        if (confirm('Mark this order as prepared and ready for shipment?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'orders.php';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'mark_prepared';

            const orderIdInput = document.createElement('input');
            orderIdInput.type = 'hidden';
            orderIdInput.name = 'order_id';
            orderIdInput.value = '<?= $order['id'] ?>';

            form.appendChild(actionInput);
            form.appendChild(orderIdInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
});

// Keep focus on scan input
scanInput.addEventListener('blur', function() {
    setTimeout(() => this.focus(), 100);
});

// Click on item to manually mark as picked
items.forEach(item => {
    item.addEventListener('click', function() {
        if (!this.classList.contains('picked')) {
            markItemPicked(this);
        }
    });
});
</script>

<?php
warehouse_portal_render_layout_end();
