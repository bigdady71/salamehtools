<?php
/**
 * Sales Rep Order Acceptance Page
 *
 * This page allows sales reps to view and accept orders that are ready for handover.
 * When they accept, stock is transferred from warehouse to their van.
 *
 * Order Flow:
 * 1. Warehouse marks order as "ready_for_handover"
 * 2. Sales rep sees it here and clicks "Accept Order"
 * 3. OTP verification required (mutual confirmation)
 * 4. Upon acceptance, stock is deducted from warehouse and added to van stock
 * 5. Order status changes to "handed_to_sales_rep"
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/OrderLifecycle.php';

$user = sales_portal_bootstrap();
$pdo = db();

// Initialize OrderLifecycle
$lifecycle = new OrderLifecycle($pdo);
$lifecycle->setUser((int)$user['id'], 'sales_rep');

// Handle OTP verification from sales rep side
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp_sales_rep') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $warehouseOtpInput = trim($_POST['warehouse_otp'] ?? '');

    if ($orderId > 0 && $warehouseOtpInput !== '') {
        try {
            $pdo->beginTransaction();

            // Get OTP record
            $otpStmt = $pdo->prepare("
                SELECT * FROM order_transfer_otps
                WHERE order_id = ? AND expires_at > NOW()
            ");
            $otpStmt->execute([$orderId]);
            $otpRecord = $otpStmt->fetch(PDO::FETCH_ASSOC);

            if (!$otpRecord) {
                $_SESSION['error'] = 'OTP expired or not found. Please ask warehouse to regenerate.';
            } elseif ($otpRecord['warehouse_otp'] !== $warehouseOtpInput) {
                $_SESSION['error'] = 'Invalid Warehouse OTP code!';
            } else {
                // Mark sales rep as verified
                $updateStmt = $pdo->prepare("
                    UPDATE order_transfer_otps
                    SET sales_rep_verified_at = NOW(),
                        sales_rep_verified_by = ?
                    WHERE order_id = ?
                ");
                $updateStmt->execute([(int)$user['id'], $orderId]);

                // Check if both parties have verified
                $checkStmt = $pdo->prepare("
                    SELECT * FROM order_transfer_otps
                    WHERE order_id = ?
                    AND warehouse_verified_at IS NOT NULL
                    AND sales_rep_verified_at IS NOT NULL
                ");
                $checkStmt->execute([$orderId]);
                $bothVerified = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($bothVerified) {
                    // Both verified - Use lifecycle to transfer stock
                    $pdo->commit();

                    $result = $lifecycle->acceptBySalesRep($orderId, (int)$user['id']);

                    if ($result['success']) {
                        $_SESSION['success'] = 'Order accepted! Stock has been transferred to your van.';
                    } else {
                        $_SESSION['error'] = 'OTP verified but stock transfer failed: ' . $result['message'];
                    }
                } else {
                    $pdo->commit();
                    $_SESSION['success'] = 'Your OTP verified! Waiting for warehouse verification...';
                }
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
    }

    header('Location: accept_orders.php');
    exit;
}

// Handle complete order (when sales rep delivers to customer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId > 0) {
        $result = $lifecycle->completeOrder($orderId);
        if ($result['success']) {
            $_SESSION['success'] = 'Order marked as completed!';
        } else {
            $_SESSION['error'] = $result['message'];
        }
    }

    header('Location: accept_orders.php');
    exit;
}

// Get orders ready for this sales rep
$readyOrdersStmt = $pdo->prepare("
    SELECT
        o.id,
        o.order_number,
        o.status,
        o.order_type,
        o.created_at,
        o.notes as order_notes,
        c.name as customer_name,
        c.phone as customer_phone,
        c.location as customer_location,
        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
        (SELECT SUM(quantity * unit_price_usd) FROM order_items WHERE order_id = o.id) as total_value
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE o.sales_rep_id = :sales_rep_id
    AND o.status IN ('ready', 'ready_for_handover', 'handed_to_sales_rep')
    ORDER BY
        CASE o.status
            WHEN 'ready' THEN 1
            WHEN 'ready_for_handover' THEN 1
            WHEN 'handed_to_sales_rep' THEN 2
        END,
        o.created_at ASC
");
$readyOrdersStmt->execute(['sales_rep_id' => $user['id']]);
$orders = $readyOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'ready' => 'Ready for Pickup',
    'ready_for_handover' => 'Ready for Pickup',
    'handed_to_sales_rep' => 'In Your Van',
];

$statusStyles = [
    'ready' => 'background:#d1fae5;color:#065f46;',
    'ready_for_handover' => 'background:#d1fae5;color:#065f46;',
    'handed_to_sales_rep' => 'background:#dbeafe;color:#1e40af;',
];

$title = 'Accept Orders - Sales Portal';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Orders Ready for Pickup',
    'subtitle' => 'Accept orders from warehouse and load to your van',
    'user' => $user,
    'active' => 'accept_orders',
]);

// Display flash messages
if (isset($_SESSION['success'])) {
    echo '<div style="background:#d1fae5;color:#065f46;padding:16px;border-radius:8px;margin-bottom:24px;">';
    echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo '<div style="background:#fee2e2;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:24px;">';
    echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
    unset($_SESSION['error']);
}
?>

<!-- Info Box -->
<div class="card" style="background:linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%);border:none;margin-bottom:24px;">
    <h3 style="margin:0 0 8px;color:#1e40af;">How Order Handover Works</h3>
    <ol style="margin:0;padding-left:20px;color:#3730a3;line-height:1.8;">
        <li>Warehouse marks your order as <strong>Ready for Pickup</strong></li>
        <li>You come to warehouse and verify with OTP codes</li>
        <li>Once both OTPs verified, <strong>stock transfers to your van</strong></li>
        <li>Deliver to customer and mark as <strong>Completed</strong></li>
    </ol>
</div>

<?php if (empty($orders)): ?>
    <div class="card">
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            No orders ready for pickup at this time.
        </p>
    </div>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <?php
        // Get order items
        $itemsStmt = $pdo->prepare("
            SELECT
                oi.quantity,
                oi.unit_price_usd as unit_price,
                p.sku,
                p.item_name,
                p.unit,
                p.image_url
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY p.item_name ASC
        ");
        $itemsStmt->execute([$order['id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get OTP data if order is ready
        $otpData = null;
        if (in_array($order['status'], ['ready', 'ready_for_handover'])) {
            $otpStmt = $pdo->prepare("
                SELECT * FROM order_transfer_otps
                WHERE order_id = ? AND expires_at > NOW()
            ");
            $otpStmt->execute([$order['id']]);
            $otpData = $otpStmt->fetch(PDO::FETCH_ASSOC);
        }
        ?>

        <div class="card" style="margin-bottom:24px;border:2px solid <?= $order['status'] === 'handed_to_sales_rep' ? '#3b82f6' : '#22c55e' ?>;">
            <!-- Order Header -->
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px;">
                <div>
                    <h3 style="margin:0 0 4px;">
                        <?= htmlspecialchars($order['order_number'] ?? 'ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <p style="margin:0;color:var(--muted);">
                        <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($order['customer_phone']): ?>
                            • <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($order['customer_location']): ?>
                        <p style="margin:4px 0 0;font-size:0.85rem;color:var(--muted);">
                            📍 <?= htmlspecialchars($order['customer_location'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <span style="display:inline-block;padding:6px 12px;border-radius:4px;font-weight:600;font-size:0.85rem;<?= $statusStyles[$order['status']] ?? '' ?>">
                        <?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <p style="margin:8px 0 0;font-size:0.85rem;color:var(--muted);">
                        <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
                    </p>
                </div>
            </div>

            <!-- Items Summary -->
            <div style="background:var(--bg);border-radius:8px;padding:12px;margin-bottom:16px;">
                <strong style="font-size:0.9rem;"><?= count($items) ?> item(s)</strong>
                <?php if ($order['total_value']): ?>
                    <span style="float:right;font-weight:600;">Total: $<?= number_format((float)$order['total_value'], 2) ?></span>
                <?php endif; ?>

                <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($items as $item): ?>
                        <div style="display:flex;align-items:center;gap:12px;padding:8px;background:#fff;border-radius:4px;">
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                     style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                            <?php else: ?>
                                <div style="width:40px;height:40px;background:#e5e7eb;border-radius:4px;"></div>
                            <?php endif; ?>
                            <div style="flex:1;">
                                <div style="font-weight:500;"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="font-size:0.8rem;color:var(--muted);">SKU: <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:600;"><?= number_format((float)$item['quantity'], 0) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="font-size:0.8rem;color:var(--muted);">$<?= number_format((float)$item['unit_price'], 2) ?> each</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Action Section -->
            <?php if (in_array($order['status'], ['ready', 'ready_for_handover'])): ?>
                <?php if ($otpData): ?>
                    <?php
                    $warehouseVerified = $otpData['warehouse_verified_at'] !== null;
                    $salesRepVerified = $otpData['sales_rep_verified_at'] !== null;
                    $bothVerified = $warehouseVerified && $salesRepVerified;
                    ?>

                    <?php if ($bothVerified): ?>
                        <div style="text-align:center;padding:16px;background:#22c55e;color:#fff;border-radius:8px;font-weight:700;">
                            ✓ VERIFIED - LOADING STOCK TO VAN...
                        </div>
                    <?php else: ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <!-- Your OTP to show warehouse -->
                            <div style="background:#fef3c7;padding:16px;border-radius:8px;border:2px solid #f59e0b;">
                                <div style="font-size:0.75rem;font-weight:600;color:#92400e;margin-bottom:8px;">YOUR OTP (Show to Warehouse):</div>
                                <div style="font-size:2rem;font-weight:700;letter-spacing:8px;color:#000;text-align:center;font-family:monospace;">
                                    <?= htmlspecialchars($otpData['sales_rep_otp'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if ($salesRepVerified): ?>
                                    <div style="margin-top:8px;text-align:center;color:#22c55e;font-weight:600;">✓ Your code verified</div>
                                <?php endif; ?>
                            </div>

                            <!-- Enter warehouse OTP -->
                            <form method="POST" style="background:#e0e7ff;padding:16px;border-radius:8px;border:2px solid #6366f1;">
                                <input type="hidden" name="action" value="verify_otp_sales_rep">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <div style="font-size:0.75rem;font-weight:600;color:#3730a3;margin-bottom:8px;">Enter Warehouse OTP:</div>
                                <input type="text" name="warehouse_otp" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required
                                       style="width:100%;padding:12px;font-size:1.5rem;text-align:center;letter-spacing:8px;font-family:monospace;border:2px solid #000;font-weight:700;border-radius:4px;"
                                       <?= $salesRepVerified ? 'disabled' : '' ?>>
                                <?php if (!$salesRepVerified): ?>
                                    <button type="submit" style="width:100%;margin-top:12px;padding:12px;background:#000;color:#fff;border:none;cursor:pointer;font-weight:600;border-radius:4px;">
                                        VERIFY & ACCEPT ORDER
                                    </button>
                                <?php else: ?>
                                    <div style="margin-top:12px;padding:8px;background:#22c55e;color:#fff;font-weight:600;text-align:center;border-radius:4px;">
                                        ✓ Waiting for Warehouse
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="text-align:center;padding:16px;background:#fee2e2;color:#991b1b;border-radius:8px;">
                        <strong>OTP Expired</strong><br>
                        <span style="font-size:0.9rem;">Please ask warehouse to re-mark this order as ready.</span>
                    </div>
                <?php endif; ?>
            <?php elseif ($order['status'] === 'handed_to_sales_rep'): ?>
                <div style="display:flex;gap:12px;justify-content:center;">
                    <form method="POST" onsubmit="return confirm('Mark this order as completed? This confirms delivery to customer.');">
                        <input type="hidden" name="action" value="complete_order">
                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                        <button type="submit" style="padding:12px 32px;background:#22c55e;color:#fff;border:none;cursor:pointer;font-weight:600;font-size:1rem;border-radius:8px;">
                            ✓ MARK AS DELIVERED
                        </button>
                    </form>
                </div>
                <p style="text-align:center;margin:12px 0 0;font-size:0.85rem;color:var(--muted);">
                    Stock is in your van. Click above when delivered to customer.
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
// Double-click prevention for all forms
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            // Prevent double submission
            if (submitBtn.dataset.submitting === 'true') {
                e.preventDefault();
                return false;
            }
            submitBtn.dataset.submitting = 'true';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.7';

            // Store original text and show loading
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Processing...';

            // Re-enable after 10 seconds (fallback for failed submissions)
            setTimeout(() => {
                submitBtn.dataset.submitting = 'false';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.textContent = originalText;
            }, 10000);
        }
    });
});
</script>

<?php
sales_portal_render_layout_end();
