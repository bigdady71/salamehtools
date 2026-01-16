<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();
$userId = (int)$user['id'];

$flashes = [];

// Handle adjustment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'message' => 'Invalid CSRF token. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'adjust_stock') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $adjustmentType = $_POST['adjustment_type'] ?? '';
            $quantity = (float)($_POST['quantity'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $note = trim($_POST['note'] ?? '');

            $errors = [];

            if ($productId <= 0) {
                $errors[] = 'Please select a product.';
            }

            if (!in_array($adjustmentType, ['add', 'remove', 'set'])) {
                $errors[] = 'Please select an adjustment type.';
            }

            if ($quantity <= 0 && $adjustmentType !== 'set') {
                $errors[] = 'Quantity must be greater than zero.';
            }

            if ($quantity < 0 && $adjustmentType === 'set') {
                $errors[] = 'Stock level cannot be negative.';
            }

            if ($reason === '') {
                $errors[] = 'Please select a reason.';
            }

            if ($errors) {
                $flashes[] = ['type' => 'error', 'message' => implode(' ', $errors)];
            } else {
                try {
                    $pdo->beginTransaction();

                    // Get current stock
                    $checkStmt = $pdo->prepare("SELECT sku, item_name, quantity_on_hand FROM products WHERE id = :id");
                    $checkStmt->execute([':id' => $productId]);
                    $product = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        throw new Exception('Product not found.');
                    }

                    $currentQty = (float)$product['quantity_on_hand'];
                    $deltaQty = 0;
                    $newQty = 0;

                    switch ($adjustmentType) {
                        case 'add':
                            $deltaQty = $quantity;
                            $newQty = $currentQty + $quantity;
                            break;
                        case 'remove':
                            $deltaQty = -$quantity;
                            $newQty = $currentQty - $quantity;
                            break;
                        case 'set':
                            $deltaQty = $quantity - $currentQty;
                            $newQty = $quantity;
                            break;
                    }

                    if ($newQty < 0) {
                        throw new Exception('Cannot reduce stock below zero. Current: ' . $currentQty . ', Requested change: ' . $deltaQty);
                    }

                    // Update warehouse stock
                    $updateStmt = $pdo->prepare("UPDATE products SET quantity_on_hand = :qty, updated_at = NOW() WHERE id = :id");
                    $updateStmt->execute([':qty' => $newQty, ':id' => $productId]);

                    // Log to warehouse_stock_adjustments table
                    $logStmt = $pdo->prepare("
                        INSERT INTO warehouse_stock_adjustments
                        (product_id, performed_by, adjustment_type, delta_qty, previous_qty, new_qty, reason, note, created_at)
                        VALUES (:product_id, :performed_by, :type, :delta, :prev, :new, :reason, :note, NOW())
                    ");
                    $logStmt->execute([
                        ':product_id' => $productId,
                        ':performed_by' => $userId,
                        ':type' => $adjustmentType,
                        ':delta' => $deltaQty,
                        ':prev' => $currentQty,
                        ':new' => $newQty,
                        ':reason' => $reason,
                        ':note' => $note !== '' ? $note : null,
                    ]);

                    $pdo->commit();

                    $changeText = $deltaQty >= 0 ? '+' . $deltaQty : $deltaQty;
                    $flashes[] = [
                        'type' => 'success',
                        'message' => "Stock adjusted successfully for {$product['item_name']} (SKU: {$product['sku']}). Change: {$changeText}, New stock: {$newQty}"
                    ];

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Warehouse stock adjustment error: " . $e->getMessage());
                    $flashes[] = ['type' => 'error', 'message' => $e->getMessage()];
                }
            }
        }
    }
}

// Get products for dropdown
$productsStmt = $pdo->prepare("
    SELECT id, sku, item_name, quantity_on_hand, unit, topcat_name as category
    FROM products
    WHERE is_active = 1
    ORDER BY item_name ASC
");
$productsStmt->execute();
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent adjustments
$recentStmt = $pdo->prepare("
    SELECT
        wsa.*,
        p.sku,
        p.item_name,
        u.name as performed_by_name
    FROM warehouse_stock_adjustments wsa
    INNER JOIN products p ON p.id = wsa.product_id
    LEFT JOIN users u ON u.id = wsa.performed_by
    ORDER BY wsa.created_at DESC
    LIMIT 50
");
$recentStmt->execute();
$recentAdjustments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

$title = 'Inventory Adjustments - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Inventory Adjustments',
    'subtitle' => 'Correct stock discrepancies and record adjustments',
    'user' => $user,
    'active' => 'adjustments',
]);
?>

<style>
.page-grid {
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 24px;
}

@media (max-width: 1100px) {
    .page-grid {
        grid-template-columns: 1fr;
    }
}

.card {
    background: var(--bg-panel);
    border-radius: 14px;
    padding: 24px;
    border: 1px solid var(--border);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.card h2 {
    margin: 0 0 20px;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    font-size: 0.9rem;
    color: var(--text);
}

.form-group select,
.form-group input,
.form-group textarea {
    width: 100%;
    padding: 12px 14px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 0.95rem;
    background: var(--bg-panel);
    color: var(--text);
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group select:focus,
.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.form-group textarea {
    min-height: 80px;
    resize: vertical;
}

.form-group small {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 0.85rem;
}

.type-buttons {
    display: flex;
    gap: 10px;
}

.type-btn {
    flex: 1;
    padding: 14px 16px;
    border: 2px solid var(--border);
    border-radius: 10px;
    background: var(--bg-panel);
    cursor: pointer;
    text-align: center;
    font-weight: 600;
    transition: all 0.2s;
}

.type-btn:hover {
    border-color: var(--accent);
}

.type-btn.active {
    border-color: var(--accent);
    background: rgba(59, 130, 246, 0.1);
    color: var(--accent);
}

.type-btn.add.active {
    border-color: #22c55e;
    background: rgba(34, 197, 94, 0.1);
    color: #16a34a;
}

.type-btn.remove.active {
    border-color: #ef4444;
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
}

.type-btn.set.active {
    border-color: #f59e0b;
    background: rgba(245, 158, 11, 0.1);
    color: #d97706;
}

.type-btn input {
    display: none;
}

.btn-submit {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-submit:hover {
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.35);
    transform: translateY(-1px);
}

.flash {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: 500;
}

.flash.success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.flash.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.current-stock {
    padding: 16px;
    background: var(--bg-panel-alt);
    border-radius: 10px;
    margin-bottom: 18px;
    display: none;
}

.current-stock.visible {
    display: block;
}

.current-stock .label {
    font-size: 0.85rem;
    color: var(--muted);
}

.current-stock .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text);
}

.adjustments-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 600px;
    overflow-y: auto;
}

.adjustment-item {
    padding: 16px;
    background: var(--bg-panel-alt);
    border-radius: 12px;
    border: 1px solid var(--border);
}

.adjustment-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 10px;
}

.adjustment-product {
    font-weight: 600;
    color: var(--text);
}

.adjustment-sku {
    font-size: 0.85rem;
    color: var(--muted);
}

.adjustment-delta {
    font-weight: 700;
    font-size: 1.1rem;
    padding: 4px 12px;
    border-radius: 8px;
}

.adjustment-delta.positive {
    background: #d1fae5;
    color: #059669;
}

.adjustment-delta.negative {
    background: #fee2e2;
    color: #dc2626;
}

.adjustment-details {
    font-size: 0.9rem;
    color: var(--muted);
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
}

.adjustment-details span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.reason-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
}

.reason-damaged { background: #fee2e2; color: #991b1b; }
.reason-found { background: #d1fae5; color: #065f46; }
.reason-count_correction { background: #fef3c7; color: #92400e; }
.reason-receiving { background: #dbeafe; color: #1e40af; }
.reason-transfer { background: #f3e8ff; color: #7c3aed; }
.reason-other { background: #f3f4f6; color: #374151; }

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
}

.empty-state .icon {
    font-size: 3rem;
    margin-bottom: 12px;
}

.product-search {
    position: relative;
}

.product-search input {
    padding-left: 40px;
}

.product-search::before {
    content: "🔍";
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1rem;
}
</style>

<?php foreach ($flashes as $flash): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endforeach; ?>

<div class="page-grid">
    <!-- Adjustment Form -->
    <div class="card">
        <h2>📦 New Adjustment</h2>

        <form method="POST" id="adjustmentForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="adjust_stock">

            <div class="form-group">
                <label>Product <span style="color:#ef4444;">*</span></label>
                <select name="product_id" id="productSelect" required>
                    <option value="">Search or select product...</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"
                                data-stock="<?= (float)$p['quantity_on_hand'] ?>"
                                data-unit="<?= htmlspecialchars($p['unit'] ?? 'units') ?>">
                            <?= htmlspecialchars($p['sku']) ?> - <?= htmlspecialchars($p['item_name']) ?>
                            <?= $p['category'] ? ' [' . htmlspecialchars($p['category']) . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="current-stock" id="currentStock">
                <div class="label">Current Warehouse Stock</div>
                <div class="value"><span id="stockValue">0</span> <span id="stockUnit">units</span></div>
            </div>

            <div class="form-group">
                <label>Adjustment Type <span style="color:#ef4444;">*</span></label>
                <div class="type-buttons">
                    <label class="type-btn add">
                        <input type="radio" name="adjustment_type" value="add" required>
                        ➕ Add
                    </label>
                    <label class="type-btn remove">
                        <input type="radio" name="adjustment_type" value="remove">
                        ➖ Remove
                    </label>
                    <label class="type-btn set">
                        <input type="radio" name="adjustment_type" value="set">
                        🎯 Set To
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label>Quantity <span style="color:#ef4444;">*</span></label>
                <input type="number" name="quantity" id="quantityInput" min="0" step="0.001" required placeholder="Enter quantity">
                <small id="quantityHint">Enter the quantity to add or remove</small>
            </div>

            <div class="form-group">
                <label>Reason <span style="color:#ef4444;">*</span></label>
                <select name="reason" required>
                    <option value="">Select reason...</option>
                    <option value="count_correction">Count Correction (Physical count differs)</option>
                    <option value="damaged">Damaged / Expired Stock</option>
                    <option value="found">Found Items (Previously untracked)</option>
                    <option value="receiving">Stock Receiving (From supplier)</option>
                    <option value="transfer">Internal Transfer</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="note" placeholder="Optional: Add details about this adjustment..."></textarea>
            </div>

            <button type="submit" class="btn-submit">
                ✅ Submit Adjustment
            </button>
        </form>
    </div>

    <!-- Recent Adjustments -->
    <div class="card">
        <h2>📋 Recent Adjustments</h2>

        <?php if (empty($recentAdjustments)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No adjustments recorded yet</p>
            </div>
        <?php else: ?>
            <div class="adjustments-list">
                <?php foreach ($recentAdjustments as $adj): ?>
                    <?php
                    $deltaQty = (float)$adj['delta_qty'];
                    $deltaClass = $deltaQty >= 0 ? 'positive' : 'negative';
                    $deltaSign = $deltaQty >= 0 ? '+' : '';
                    $reason = $adj['reason'] ?? 'other';
                    ?>
                    <div class="adjustment-item">
                        <div class="adjustment-header">
                            <div>
                                <div class="adjustment-product"><?= htmlspecialchars($adj['item_name']) ?></div>
                                <div class="adjustment-sku"><?= htmlspecialchars($adj['sku']) ?></div>
                            </div>
                            <div class="adjustment-delta <?= $deltaClass ?>">
                                <?= $deltaSign ?><?= number_format($deltaQty, 2) ?>
                            </div>
                        </div>
                        <div class="adjustment-details">
                            <span class="reason-badge reason-<?= htmlspecialchars($reason) ?>">
                                <?= ucwords(str_replace('_', ' ', $reason)) ?>
                            </span>
                            <span>📊 <?= number_format((float)$adj['previous_qty'], 2) ?> → <?= number_format((float)$adj['new_qty'], 2) ?></span>
                            <span>👤 <?= htmlspecialchars($adj['performed_by_name'] ?? 'Unknown') ?></span>
                            <span>🕐 <?= date('M j, g:i A', strtotime($adj['created_at'])) ?></span>
                        </div>
                        <?php if (!empty($adj['note'])): ?>
                            <div style="margin-top:8px; font-size:0.85rem; color:var(--muted); font-style:italic;">
                                📝 <?= htmlspecialchars($adj['note']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Handle product selection to show current stock
document.getElementById('productSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const stockDisplay = document.getElementById('currentStock');

    if (this.value) {
        const stock = selected.dataset.stock || '0';
        const unit = selected.dataset.unit || 'units';
        document.getElementById('stockValue').textContent = parseFloat(stock).toFixed(2);
        document.getElementById('stockUnit').textContent = unit;
        stockDisplay.classList.add('visible');
    } else {
        stockDisplay.classList.remove('visible');
    }
});

// Handle adjustment type buttons
document.querySelectorAll('.type-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        const type = this.querySelector('input').value;
        const hint = document.getElementById('quantityHint');
        const input = document.getElementById('quantityInput');

        if (type === 'add') {
            hint.textContent = 'Enter the quantity to ADD to warehouse stock';
            input.placeholder = 'Quantity to add';
        } else if (type === 'remove') {
            hint.textContent = 'Enter the quantity to REMOVE from warehouse stock';
            input.placeholder = 'Quantity to remove';
        } else if (type === 'set') {
            hint.textContent = 'Set the warehouse stock to this exact value';
            input.placeholder = 'New stock level';
        }
    });
});

// Form validation
document.getElementById('adjustmentForm').addEventListener('submit', function(e) {
    const type = document.querySelector('input[name="adjustment_type"]:checked');
    if (!type) {
        e.preventDefault();
        alert('Please select an adjustment type (Add, Remove, or Set To)');
        return;
    }

    const qty = parseFloat(document.getElementById('quantityInput').value);
    if (isNaN(qty) || qty < 0) {
        e.preventDefault();
        alert('Please enter a valid quantity');
        return;
    }

    // Confirm before submission
    const product = document.getElementById('productSelect').options[document.getElementById('productSelect').selectedIndex].text;
    const action = type.value === 'add' ? 'ADD ' + qty : type.value === 'remove' ? 'REMOVE ' + qty : 'SET stock to ' + qty;

    if (!confirm(`Are you sure you want to ${action} for:\n${product}?`)) {
        e.preventDefault();
    }
});
</script>

<?php
warehouse_portal_render_layout_end();
