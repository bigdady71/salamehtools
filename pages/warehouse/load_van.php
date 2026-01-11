<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/van_loading_auth.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Get sales rep info
$salesRep = null;
if ($repId > 0) {
    $repStmt = $pdo->prepare("SELECT id, name, phone FROM users WHERE id = ? AND role = 'sales_rep'");
    $repStmt->execute([$repId]);
    $salesRep = $repStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$salesRep) {
    header('Location: sales_reps_stocks.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
        ];
    } elseif ($action === 'create_loading') {
        // Create new loading request
        $items = $_POST['items'] ?? [];
        $note = trim((string)($_POST['note'] ?? ''));

        $validatedItems = [];
        $errors = [];

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);

            if ($productId > 0 && $quantity > 0) {
                // Check warehouse stock
                $stockStmt = $pdo->prepare("SELECT quantity_on_hand, item_name FROM products WHERE id = ?");
                $stockStmt->execute([$productId]);
                $product = $stockStmt->fetch();

                if ($product && (float)$product['quantity_on_hand'] >= $quantity) {
                    $validatedItems[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ];
                } elseif ($product) {
                    $errors[] = "Insufficient stock for {$product['item_name']} (available: {$product['quantity_on_hand']})";
                }
            }
        }

        if (empty($validatedItems)) {
            $errors[] = 'Please add at least one product to load.';
        }

        if ($errors) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Error',
                'message' => implode('<br>', $errors),
            ];
        } else {
            try {
                $result = create_van_loading_request(
                    $pdo,
                    (int)$user['id'],
                    $repId,
                    $validatedItems,
                    $note ?: null
                );

                // Redirect to OTP page
                header("Location: load_van_otp.php?loading_id={$result['loading_id']}");
                exit;
            } catch (Exception $e) {
                error_log("Van loading creation failed: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Error',
                    'message' => 'Failed to create loading request. Please try again.',
                ];
            }
        }
    } elseif ($action === 'confirm_otp') {
        // Confirm OTP from warehouse side
        $loadingId = trim((string)($_POST['loading_id'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));

        if (!preg_match('/^\d{6}$/', $otp)) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Invalid OTP',
                'message' => 'Please enter a valid 6-digit OTP code.',
            ];
        } else {
            $confirmed = confirm_loading_by_warehouse($pdo, $loadingId, $otp);

            if ($confirmed) {
                $flashes[] = [
                    'type' => 'success',
                    'title' => 'OTP Confirmed',
                    'message' => 'Your confirmation has been recorded. The loading will be processed once the sales rep confirms.',
                ];
            } else {
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Invalid OTP',
                    'message' => 'The OTP code is invalid, expired, or has already been used.',
                ];
            }
        }
    }
}

// Get products with stock
$products = $pdo->query("
    SELECT id, sku, item_name, unit, quantity_on_hand, image_url
    FROM products
    WHERE is_active = 1 AND quantity_on_hand > 0
    ORDER BY item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get pending loading requests for this warehouse user
$pendingLoadings = get_pending_loadings_for_warehouse($pdo, (int)$user['id']);

$csrfToken = csrf_token();

warehouse_portal_render_layout_start([
    'title' => 'Load Van - ' . $salesRep['name'],
    'heading' => 'Load Van Stock',
    'subtitle' => 'Loading stock for ' . htmlspecialchars($salesRep['name'], ENT_QUOTES, 'UTF-8'),
    'user' => $user,
    'active' => 'sales_reps_stocks',
]);
?>

<style>
    .load-container {
        max-width: 1000px;
    }
    .sales-rep-info {
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .sales-rep-info .icon {
        font-size: 2.5rem;
    }
    .sales-rep-info h2 {
        margin: 0 0 4px;
        color: white;
    }
    .sales-rep-info p {
        margin: 0;
        opacity: 0.9;
    }
    .product-search {
        margin-bottom: 20px;
    }
    .product-search input {
        width: 100%;
        padding: 14px 16px;
        font-size: 1rem;
        border: 2px solid var(--border);
        border-radius: 10px;
        transition: border-color 0.2s;
    }
    .product-search input:focus {
        outline: none;
        border-color: var(--primary);
    }
    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
        max-height: 400px;
        overflow-y: auto;
        padding: 4px;
        margin-bottom: 24px;
    }
    .product-card {
        background: white;
        border: 2px solid var(--border);
        border-radius: 10px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .product-card:hover {
        border-color: var(--primary);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .product-card.selected {
        border-color: var(--success);
        background: #f0fdf4;
    }
    .product-card .product-image {
        width: 100%;
        height: 80px;
        object-fit: contain;
        background: #f9fafb;
        border-radius: 6px;
        margin-bottom: 8px;
    }
    .product-card .product-image-placeholder {
        width: 100%;
        height: 80px;
        background: #f3f4f6;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        color: #9ca3af;
        margin-bottom: 8px;
    }
    .product-card .product-name {
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .product-card .product-sku {
        font-size: 0.8rem;
        color: var(--muted);
        margin-bottom: 4px;
    }
    .product-card .product-stock {
        font-size: 0.85rem;
        color: var(--success);
        font-weight: 600;
    }
    .selected-products {
        background: #f0f9ff;
        border: 2px solid #0ea5e9;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
    }
    .selected-products h3 {
        margin: 0 0 16px;
        color: #0369a1;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .selected-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: white;
        border: 1px solid var(--border);
        border-radius: 8px;
        margin-bottom: 8px;
    }
    .selected-item .item-info {
        flex: 1;
    }
    .selected-item .item-name {
        font-weight: 600;
        margin-bottom: 2px;
    }
    .selected-item .item-sku {
        font-size: 0.85rem;
        color: var(--muted);
    }
    .selected-item .item-qty {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .selected-item .item-qty input {
        width: 80px;
        padding: 8px;
        border: 2px solid var(--border);
        border-radius: 6px;
        text-align: center;
        font-weight: 600;
    }
    .selected-item .remove-btn {
        background: #fee2e2;
        color: #dc2626;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 1.2rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .selected-item .remove-btn:hover {
        background: #fecaca;
    }
    .note-section textarea {
        width: 100%;
        padding: 12px;
        border: 2px solid var(--border);
        border-radius: 8px;
        resize: vertical;
        min-height: 80px;
    }
    .btn-submit {
        width: 100%;
        padding: 16px;
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-submit:hover {
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4);
        transform: translateY(-1px);
    }
    .btn-submit:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    .flash {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 16px;
        border: 2px solid;
    }
    .flash-success {
        background: #d1fae5;
        border-color: #065f46;
        color: #065f46;
    }
    .flash-error {
        background: #fee2e2;
        border-color: #991b1b;
        color: #991b1b;
    }
    .pending-section {
        margin-top: 32px;
    }
    .pending-card {
        background: white;
        border: 2px solid var(--border);
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 12px;
    }
    .pending-card.awaiting-you {
        border-left: 4px solid #3b82f6;
    }
    .pending-card.awaiting-other {
        border-left: 4px solid #f59e0b;
    }
    .otp-form {
        display: flex;
        gap: 12px;
        align-items: flex-end;
        margin-top: 12px;
        padding: 12px;
        background: #eff6ff;
        border-radius: 8px;
    }
    .otp-form input {
        width: 120px;
        padding: 10px;
        border: 2px solid #3b82f6;
        border-radius: 6px;
        font-size: 1.1rem;
        font-weight: 600;
        text-align: center;
        letter-spacing: 0.2em;
    }
    .otp-form button {
        padding: 10px 20px;
        background: #3b82f6;
        color: white;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
    }
    .otp-form button:hover {
        background: #2563eb;
    }
    .info-box {
        background: #fef3c7;
        border: 2px solid #f59e0b;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 24px;
    }
    .info-box h3 {
        margin: 0 0 8px;
        color: #92400e;
    }
    .info-box p {
        margin: 0;
        color: #92400e;
        line-height: 1.6;
    }
</style>

<div class="load-container">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <strong><?= htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <?= $flash['message'] ?>
        </div>
    <?php endforeach; ?>

    <div class="sales-rep-info">
        <div class="icon">🚚</div>
        <div>
            <h2><?= htmlspecialchars($salesRep['name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <?php if ($salesRep['phone']): ?>
                <p><?= htmlspecialchars($salesRep['phone'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="info-box">
        <h3>Two-Factor Authorization Required</h3>
        <p>
            After selecting products, both you and the sales rep must confirm with OTP codes.
            This ensures both parties agree to the stock transfer.
        </p>
    </div>

    <div class="card">
        <h2>Select Products to Load</h2>

        <div class="product-search">
            <input type="text" id="productSearch" placeholder="Search by name, SKU, or barcode..." autocomplete="off">
        </div>

        <div class="product-grid" id="productGrid">
            <?php foreach ($products as $product): ?>
                <div class="product-card"
                     data-product-id="<?= $product['id'] ?>"
                     data-product-name="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                     data-product-sku="<?= htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     data-product-stock="<?= number_format((float)$product['quantity_on_hand'], 2) ?>"
                     data-product-unit="<?= htmlspecialchars($product['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                             class="product-image">
                    <?php else: ?>
                        <div class="product-image-placeholder">📦</div>
                    <?php endif; ?>
                    <div class="product-name"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="product-sku"><?= htmlspecialchars($product['sku'] ?? 'No SKU', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="product-stock"><?= number_format((float)$product['quantity_on_hand'], 0) ?> <?= htmlspecialchars($product['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="POST" id="loadingForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="create_loading">

        <div class="selected-products" id="selectedProducts" style="display: none;">
            <h3>📋 Products to Load</h3>
            <div id="selectedProductsList"></div>
        </div>

        <div class="card note-section">
            <h2>Note (Optional)</h2>
            <textarea name="note" placeholder="Add any notes about this loading..."></textarea>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn" disabled>
            Generate Loading OTPs
        </button>
    </form>

    <?php if (!empty($pendingLoadings)): ?>
        <div class="pending-section">
            <h2>Pending Loading Requests</h2>
            <?php foreach ($pendingLoadings as $loading): ?>
                <?php
                $needsYourConfirm = !$loading['warehouse_confirmed'];
                $cardClass = $needsYourConfirm ? 'awaiting-you' : 'awaiting-other';
                ?>
                <div class="pending-card <?= $cardClass ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                        <div>
                            <strong><?= htmlspecialchars($loading['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div style="font-size:0.9rem;color:var(--muted);">
                                <?= (int)$loading['item_count'] ?> products,
                                <?= number_format((float)$loading['total_quantity'], 0) ?> units total
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:0.85rem;color:var(--muted);">
                                Expires: <?= date('M j, g:i A', strtotime($loading['expires_at'])) ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($loading['warehouse_confirmed']): ?>
                        <div style="padding:12px;background:#fef3c7;border-radius:8px;color:#92400e;">
                            ✓ You have confirmed. Waiting for sales rep to confirm.
                        </div>
                    <?php else: ?>
                        <form method="POST" class="otp-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="confirm_otp">
                            <input type="hidden" name="loading_id" value="<?= htmlspecialchars($loading['loading_id'], ENT_QUOTES, 'UTF-8') ?>">
                            <div>
                                <label style="font-size:0.85rem;font-weight:600;color:#1e40af;display:block;margin-bottom:4px;">
                                    Enter Your OTP
                                </label>
                                <input type="text" name="otp" placeholder="000000" maxlength="6" pattern="\d{6}" required>
                            </div>
                            <button type="submit">Confirm</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    let selectedProducts = {};

    // Product search
    document.getElementById('productSearch').addEventListener('input', function(e) {
        const search = e.target.value.toLowerCase();
        document.querySelectorAll('.product-card').forEach(card => {
            const name = card.dataset.productName.toLowerCase();
            const sku = card.dataset.productSku.toLowerCase();
            if (name.includes(search) || sku.includes(search)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });

    // Product selection
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function() {
            const productId = this.dataset.productId;
            const productName = this.dataset.productName;
            const productSku = this.dataset.productSku;
            const productStock = parseFloat(this.dataset.productStock);
            const productUnit = this.dataset.productUnit;

            if (selectedProducts[productId]) {
                // Already selected, remove it
                delete selectedProducts[productId];
                this.classList.remove('selected');
            } else {
                // Add to selection
                selectedProducts[productId] = {
                    name: productName,
                    sku: productSku,
                    stock: productStock,
                    unit: productUnit,
                    quantity: 1
                };
                this.classList.add('selected');
            }

            updateSelectedProductsUI();
        });
    });

    function updateSelectedProductsUI() {
        const container = document.getElementById('selectedProducts');
        const list = document.getElementById('selectedProductsList');
        const submitBtn = document.getElementById('submitBtn');

        const productIds = Object.keys(selectedProducts);

        if (productIds.length === 0) {
            container.style.display = 'none';
            submitBtn.disabled = true;
            return;
        }

        container.style.display = 'block';
        submitBtn.disabled = false;

        let html = '';
        productIds.forEach(productId => {
            const product = selectedProducts[productId];
            html += `
                <div class="selected-item" data-product-id="${productId}">
                    <div class="item-info">
                        <div class="item-name">${escapeHtml(product.name)}</div>
                        <div class="item-sku">${escapeHtml(product.sku)} | Stock: ${product.stock} ${escapeHtml(product.unit)}</div>
                    </div>
                    <div class="item-qty">
                        <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                        <input type="number"
                               name="items[${productId}][quantity]"
                               value="${product.quantity}"
                               min="1"
                               max="${product.stock}"
                               step="0.01"
                               onchange="updateQuantity('${productId}', this.value)">
                        <span>${escapeHtml(product.unit)}</span>
                    </div>
                    <button type="button" class="remove-btn" onclick="removeProduct('${productId}')">&times;</button>
                </div>
            `;
        });
        list.innerHTML = html;
    }

    function updateQuantity(productId, quantity) {
        if (selectedProducts[productId]) {
            const maxStock = selectedProducts[productId].stock;
            quantity = parseFloat(quantity);
            if (quantity < 0.01) quantity = 0.01;
            if (quantity > maxStock) quantity = maxStock;
            selectedProducts[productId].quantity = quantity;
        }
    }

    function removeProduct(productId) {
        delete selectedProducts[productId];
        document.querySelector(`.product-card[data-product-id="${productId}"]`)?.classList.remove('selected');
        updateSelectedProductsUI();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Form submission
    document.getElementById('loadingForm').addEventListener('submit', function(e) {
        const productCount = Object.keys(selectedProducts).length;
        if (productCount === 0) {
            e.preventDefault();
            alert('Please select at least one product.');
            return false;
        }

        // Validate quantities
        for (const productId in selectedProducts) {
            const product = selectedProducts[productId];
            if (product.quantity <= 0 || product.quantity > product.stock) {
                e.preventDefault();
                alert(`Invalid quantity for ${product.name}. Must be between 1 and ${product.stock}.`);
                return false;
            }
        }

        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').textContent = 'Processing...';
    });
</script>

<?php
warehouse_portal_render_layout_end();
