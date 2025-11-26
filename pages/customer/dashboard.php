<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();
    
// Fetch account summary
$balanceStmt = $pdo->prepare("
    SELECT
        account_balance_lbp,
        customer_tier
    FROM customers
    WHERE id = ?
");
$balanceStmt->execute([$customerId]);
$accountInfo = $balanceStmt->fetch(PDO::FETCH_ASSOC);
$balance = (float)($accountInfo['account_balance_lbp'] ?? 0);
$tier = $accountInfo['customer_tier'] ?? 'medium';

// Calculate rewards progress (every $1000 USD = 1 gift)
$rewardThreshold = 1000; // USD
$totalSpentStmt = $pdo->prepare("
    SELECT COALESCE(SUM(o.total_usd), 0) as total_spent
    FROM orders o
    WHERE o.customer_id = ? AND o.status IN ('approved', 'processing', 'shipped', 'delivered')
");
$totalSpentStmt->execute([$customerId]);
$totalSpent = (float)$totalSpentStmt->fetchColumn();

// Calculate current progress
$currentCycle = floor($totalSpent / $rewardThreshold);
$progressInCycle = $totalSpent - ($currentCycle * $rewardThreshold);
$progressPercentage = min(100, ($progressInCycle / $rewardThreshold) * 100);
$remainingToNextGift = $rewardThreshold - $progressInCycle;
$nextGiftAt = ($currentCycle + 1) * $rewardThreshold;

// Fetch recent orders (last 5)
$ordersStmt = $pdo->prepare("
    SELECT
        o.id,
        o.created_at as order_date,
        o.order_type,
        o.status,
        o.total_usd as total_amount_usd,
        o.total_lbp as total_amount_lbp,
        COUNT(DISTINCT oi.id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.customer_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
$ordersStmt->execute([$customerId]);
$recentOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch outstanding invoices
$invoicesStmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.issued_at,
        i.due_date,
        i.total_usd as total_amount_usd,
        i.total_lbp as total_amount_lbp,
        COALESCE(SUM(p.amount_usd), 0) as paid_amount_usd,
        COALESCE(SUM(p.amount_lbp), 0) as paid_amount_lbp,
        i.status,
        DATEDIFF(NOW(), i.due_date) as days_overdue
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    LEFT JOIN payments p ON p.invoice_id = i.id
    WHERE o.customer_id = ? AND i.status != 'paid'
    GROUP BY i.id
    ORDER BY i.due_date ASC
    LIMIT 5
");
$invoicesStmt->execute([$customerId]);
$outstandingInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total outstanding
$totalOutstanding = 0;
foreach ($outstandingInvoices as $inv) {
    $totalOutstanding += (float)$inv['total_amount_usd'] - (float)$inv['paid_amount_usd'];
}

// Fetch order statistics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(o.total_usd), 0) as total_spent_usd,
        COUNT(DISTINCT CASE WHEN o.status = 'pending' THEN o.id END) as pending_orders,
        COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.id END) as delivered_orders
    FROM orders o
    WHERE o.customer_id = ?
");
$statsStmt->execute([$customerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$stats['total_orders'] = (int)$stats['total_orders'];
$stats['total_spent_usd'] = (float)$stats['total_spent_usd'];
$stats['pending_orders'] = (int)$stats['pending_orders'];
$stats['delivered_orders'] = (int)$stats['delivered_orders'];

// Fetch cart item count
$cartStmt = $pdo->prepare("SELECT COUNT(*) as count FROM customer_cart WHERE customer_id = ?");
$cartStmt->execute([$customerId]);
$cartCount = (int)$cartStmt->fetchColumn();

$title = 'Dashboard - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Welcome back, ' . $customer['name'],
    'subtitle' => 'Here\'s an overview of your account',
    'customer' => $customer,
    'active' => 'dashboard',
    'actions' => [
        ['label' => '🛍️ Browse Products', 'href' => 'products.php', 'variant' => 'primary'],
        ['label' => '🛒 View Cart (' . $cartCount . ')', 'href' => 'cart.php'],
        ['label' => '📦 My Orders', 'href' => 'orders.php'],
    ]
]);

?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.stat-card {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.2);
}
.stat-card h3 {
    margin: 0 0 8px;
    font-size: 0.85rem;
    font-weight: 500;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.stat-card .value {
    font-size: 2rem;
    font-weight: 800;
    margin: 0;
}
.stat-card.secondary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}
.stat-card.warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}
.stat-card.neutral {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
}
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}
.table-responsive {
    overflow-x: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
table th {
    text-align: left;
    padding: 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
}
table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    color: var(--muted);
}
table tr:hover {
    background: var(--bg-panel-alt);
}
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
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
.badge-partial {
    background: #fef3c7;
    color: #92400e;
}
.badge-unpaid {
    background: #fee2e2;
    color: #991b1b;
}
.badge-overdue {
    background: #991b1b;
    color: #ffffff;
}
.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: var(--muted);
}
.empty-state h3 {
    font-size: 1.2rem;
    margin: 0 0 12px;
    color: var(--text);
}
.empty-state p {
    margin: 0 0 24px;
}

/* Rewards Progress Bar Styles */
.rewards-card {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 32px;
    color: white;
    box-shadow: 0 20px 40px rgba(16, 185, 129, 0.3);
    position: relative;
    overflow: hidden;
}
.rewards-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}
.rewards-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    position: relative;
    z-index: 1;
}
.rewards-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.rewards-title .emoji {
    font-size: 2rem;
    animation: bounce 2s infinite;
}
@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
.rewards-stats {
    text-align: right;
    font-size: 0.9rem;
    opacity: 0.95;
}
.rewards-stats strong {
    display: block;
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 4px;
}
.progress-container {
    position: relative;
    margin-bottom: 20px;
    z-index: 1;
}
.progress-bar-bg {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50px;
    height: 40px;
    position: relative;
    overflow: visible;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}
.progress-bar-fill {
    background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%);
    height: 100%;
    border-radius: 50px;
    position: relative;
    transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 8px;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); }
    50% { box-shadow: 0 4px 20px rgba(245, 158, 11, 0.6); }
}
.progress-percentage {
    font-weight: 700;
    font-size: 0.9rem;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.gift-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 2.5rem;
    transition: all 0.3s;
    animation: wiggle 1.5s infinite;
    filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
}
@keyframes wiggle {
    0%, 100% { transform: translateY(-50%) rotate(-5deg); }
    50% { transform: translateY(-50%) rotate(5deg); }
}
.gift-icon.reached {
    animation: celebrate 0.6s;
}
@keyframes celebrate {
    0%, 100% { transform: translateY(-50%) scale(1); }
    50% { transform: translateY(-50%) scale(1.3) rotate(20deg); }
}
.milestone-marker {
    position: absolute;
    top: -8px;
    width: 2px;
    height: 56px;
    background: rgba(255, 255, 255, 0.3);
}
.milestone-label {
    position: absolute;
    top: -32px;
    font-size: 0.7rem;
    white-space: nowrap;
    transform: translateX(-50%);
    opacity: 0.8;
}
.rewards-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.95rem;
    position: relative;
    z-index: 1;
}
.rewards-info-left {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.rewards-info-left strong {
    font-size: 1.3rem;
    color: #fbbf24;
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.rewards-cta {
    background: rgba(255, 255, 255, 0.95);
    color: #059669;
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.rewards-cta:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.2);
}
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Orders</h3>
        <p class="value"><?= number_format($stats['total_orders']) ?></p>
    </div>
    <div class="stat-card secondary">
        <h3>Total Spent</h3>
        <p class="value">$<?= number_format($stats['total_spent_usd'], 2) ?></p>
    </div>
    <div class="stat-card warning">
        <h3>Outstanding Balance</h3>
        <p class="value">$<?= number_format($totalOutstanding, 2) ?></p>
    </div>
    <div class="stat-card neutral">
        <h3>Account Tier</h3>
        <p class="value"><?= ucfirst(htmlspecialchars($tier, ENT_QUOTES, 'UTF-8')) ?></p>
    </div>
</div>

<!-- Rewards Progress Card -->
<div class="rewards-card">
    <div class="rewards-header">
        <h2 class="rewards-title">
            <span class="emoji">🎁</span>
            Rewards Progress
        </h2>
        <div class="rewards-stats">
            <strong><?= $currentCycle ?></strong>
            <?= $currentCycle === 1 ? 'Gift Earned' : 'Gifts Earned' ?>
        </div>
    </div>

    <div class="progress-container">
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" style="width: <?= $progressPercentage ?>%;">
                <?php if ($progressPercentage > 15): ?>
                    <span class="progress-percentage"><?= number_format($progressPercentage, 0) ?>%</span>
                <?php endif; ?>
            </div>

            <!-- Gift icon at the end -->
            <div class="gift-icon <?= $progressPercentage >= 100 ? 'reached' : '' ?>" style="left: calc(100% - 20px);">
                🎁
            </div>
        </div>
    </div>

    <div class="rewards-info">
        <div class="rewards-info-left">
            <div>
                You've spent <strong>$<?= number_format($totalSpent, 2) ?></strong> so far
            </div>
            <div style="opacity: 0.9;">
                <?php if ($remainingToNextGift > 0): ?>
                    Just <strong style="color: #fbbf24;">$<?= number_format($remainingToNextGift, 2) ?></strong> more to unlock your next gift!
                <?php else: ?>
                    🎉 You've reached a new milestone! Keep shopping to earn more!
                <?php endif; ?>
            </div>
        </div>
        <a href="products.php" class="rewards-cta">
            🛍️ Shop Now
        </a>
    </div>
</div>

<!-- Recent Orders and Outstanding Invoices -->
<div class="content-grid">
    <!-- Recent Orders -->
    <div class="card">
        <h2>Recent Orders</h2>
        <?php if (count($recentOrders) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                            <?php
                            $orderId = (int)$order['id'];
                            $orderDate = date('M d, Y', strtotime($order['order_date']));
                            $itemCount = (int)$order['item_count'];
                            $status = htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8');
                            $total = (float)$order['total_amount_usd'];
                            $badgeClass = 'badge-' . strtolower($status);
                            ?>
                            <tr>
                                <td><a href="order_details.php?id=<?= $orderId ?>">#<?= $orderId ?></a></td>
                                <td><?= $orderDate ?></td>
                                <td><?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $status ?></span></td>
                                <td>$<?= number_format($total, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 16px; text-align: right;">
                <a href="orders.php" style="color: var(--accent); font-weight: 600;">View All Orders →</a>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>No Orders Yet</h3>
                <p>Start shopping to see your orders here.</p>
                <a href="products.php" class="btn btn-primary">Browse Products</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Outstanding Invoices -->
    <div class="card">
        <h2>Outstanding Invoices</h2>
        <?php if (count($outstandingInvoices) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outstandingInvoices as $invoice): ?>
                            <?php
                            $invoiceId = (int)$invoice['id'];
                            $invoiceNum = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8');
                            $dueDate = $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A';
                            $total = (float)$invoice['total_amount_usd'];
                            $paid = (float)$invoice['paid_amount_usd'];
                            $remaining = $total - $paid;
                            $status = htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8');
                            $daysOverdue = (int)($invoice['days_overdue'] ?? 0);
                            $badgeClass = $daysOverdue > 0 ? 'badge-overdue' : 'badge-' . strtolower($status);
                            $statusLabel = $daysOverdue > 0 ? 'Overdue (' . $daysOverdue . 'd)' : $status;
                            ?>
                            <tr>
                                <td><a href="invoice_details.php?id=<?= $invoiceId ?>"><?= $invoiceNum ?></a></td>
                                <td><?= $dueDate ?></td>
                                <td>$<?= number_format($remaining, 2) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 16px; text-align: right;">
                <a href="invoices.php" style="color: var(--accent); font-weight: 600;">View All Invoices →</a>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>No Outstanding Invoices</h3>
                <p>You're all caught up!</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sales Rep Information -->
<?php if ($customer['sales_rep_name']): ?>
<div class="card">
    <h2>Your Sales Representative</h2>
    <div style="display: flex; align-items: center; gap: 24px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 200px;">
            <p style="margin: 0 0 8px; font-weight: 600; font-size: 1.1rem; color: var(--text);">
                <?= htmlspecialchars($customer['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php if ($customer['sales_rep_email']): ?>
                <p style="margin: 0 0 4px;">
                    📧 <a href="mailto:<?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </p>
            <?php endif; ?>
            <?php if ($customer['sales_rep_phone']): ?>
                <p style="margin: 0;">
                    📞 <a href="tel:<?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <div>
            <a href="contact.php" class="btn btn-primary">Send Message</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php

customer_portal_render_layout_end();
