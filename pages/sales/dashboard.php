<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/SalesPortalDashboard.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Sales Dashboard';
$pdo = db();

$dashboardData = sales_portal_dashboard_data($pdo, (int)$user['id']);
$metrics = $dashboardData['metrics'];
$deliveriesToday = $dashboardData['deliveries_today'];
$invoiceTotals = $dashboardData['invoice_totals'];
$latestOrders = $dashboardData['latest_orders'];
$pendingInvoices = $dashboardData['pending_invoices'];
$recentPayments = $dashboardData['recent_payments'];
$upcomingDeliveries = $dashboardData['upcoming_deliveries'];
$vanStockSummary = $dashboardData['van_stock_summary'];
$vanStockMovements = $dashboardData['van_stock_movements'];
$errors = $dashboardData['errors'];
$notices = $dashboardData['notices'];

$orderStatusLabels = [
    'on_hold' => 'On Hold',
    'approved' => 'Approved',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pickup',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'returned' => 'Returned',
];

$invoiceStatusLabels = [
    'draft' => 'Pending Draft',
    'pending' => 'Pending',
    'issued' => 'Issued',
    'paid' => 'Paid',
    'voided' => 'Voided',
];

$extraHead = <<<'HTML'
<style>
.sales-dashboard__alerts {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 16px;
}
.sales-dashboard__alert {
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 0.95rem;
}
.sales-dashboard__alert--error {
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}
.sales-dashboard__alert--notice {
    background: rgba(59, 130, 246, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: #1d4ed8;
}
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}
.metric-card {
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px;
    background: var(--bg-panel);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.metric-card span.label {
    font-size: 0.85rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.metric-card strong {
    font-size: 1.8rem;
}
.metric-card small {
    color: var(--muted);
}
.data-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}
.data-card {
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px;
    background: var(--bg-panel);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.data-card header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.data-card h2 {
    margin: 0;
    font-size: 1.1rem;
}
.data-card table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.data-card table th,
.data-card table td {
    padding: 8px 4px;
    text-align: left;
    border-bottom: 1px solid rgba(148, 163, 184, 0.3);
}
.data-card table th {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
}
.data-card table tr:last-child td {
    border-bottom: none;
}
.empty-state {
    padding: 16px;
    border-radius: 12px;
    background: var(--bg-panel-alt);
    color: var(--muted);
    text-align: center;
}
.van-stock-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
}
.van-stock-summary .label {
    display: block;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
    margin-bottom: 4px;
}
.badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.badge-warning { background: rgba(251, 191, 36, 0.15); color: #b45309; }
.badge-info { background: rgba(59, 130, 246, 0.15); color: #1d4ed8; }
.badge-success { background: rgba(34, 197, 94, 0.15); color: #15803d; }
.badge-neutral { background: rgba(148, 163, 184, 0.3); color: #475569; }
</style>
HTML;

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Live view of your pipeline, invoices, deliveries, and van stock.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'dashboard',
    'extra_head' => $extraHead,
]);
?>

<?php if ($errors || $notices): ?>
    <div class="sales-dashboard__alerts">
        <?php foreach ($errors as $error): ?>
            <div class="sales-dashboard__alert sales-dashboard__alert--error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($notices as $notice): ?>
            <div class="sales-dashboard__alert sales-dashboard__alert--notice">
                <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="metrics-grid">
    <article class="metric-card">
        <span class="label">Orders Today</span>
        <strong><?= number_format($metrics['orders_today'] ?? 0) ?></strong>
        <small>Created since midnight</small>
    </article>
    <article class="metric-card">
        <span class="label">Open Orders</span>
        <strong><?= number_format($metrics['open_orders'] ?? 0) ?></strong>
        <small>Not yet delivered</small>
    </article>
    <article class="metric-card">
        <span class="label">Awaiting Approval</span>
        <strong><?= number_format($metrics['awaiting_approval'] ?? 0) ?></strong>
        <small>Still on hold</small>
    </article>
    <article class="metric-card">
        <span class="label">In Transit</span>
        <strong><?= number_format($metrics['in_transit'] ?? 0) ?></strong>
        <small>Orders currently en route</small>
    </article>
    <article class="metric-card">
        <span class="label">Deliveries Today</span>
        <strong><?= number_format($deliveriesToday) ?></strong>
        <small>Scheduled for today</small>
    </article>
    <article class="metric-card">
        <span class="label">Open Receivables</span>
        <strong>$<?= number_format($invoiceTotals['usd'], 2) ?></strong>
        <small><?= number_format($invoiceTotals['lbp']) ?> LBP</small>
    </article>
</section>

<section class="data-grid">
    <article class="data-card">
        <header>
            <h2>Recent Orders</h2>
            <span class="badge badge-info">Latest</span>
        </header>
        <?php if (!$latestOrders): ?>
            <div class="empty-state">No orders found.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Total (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latestOrders as $order): ?>
                        <?php
                        $statusKey = $order['status'] ?? 'on_hold';
                        $statusLabel = $orderStatusLabels[$statusKey] ?? ucfirst((string)$statusKey);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($order['order_number'] ?? ('#' . (int)$order['id']), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small><?= htmlspecialchars($order['customer_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>$<?= number_format((float)($order['total_usd'] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="data-card">
        <header>
            <h2>Pending Invoices</h2>
            <span class="badge badge-warning">Receivables</span>
        </header>
        <?php if (!$pendingInvoices): ?>
            <div class="empty-state">No invoices issued yet.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Status</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingInvoices as $invoice): ?>
                        <?php
                        $balanceUsd = max(0.0, (float)$invoice['total_usd'] - (float)$invoice['paid_usd']);
                        $statusKey = $invoice['status'] ?? 'pending';
                        $statusLabel = $invoiceStatusLabels[$statusKey] ?? ucfirst((string)$statusKey);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($invoice['invoice_number'] ?? ('INV-' . (int)$invoice['id']), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small><?= htmlspecialchars($invoice['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                $<?= number_format($balanceUsd, 2) ?><br>
                                <small><?= number_format(max(0.0, (float)$invoice['total_lbp'] - (float)$invoice['paid_lbp'])) ?> LBP</small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="data-card">
        <header>
            <h2>Upcoming Deliveries</h2>
            <span class="badge badge-info">Logistics</span>
        </header>
        <?php if (!$upcomingDeliveries): ?>
            <div class="empty-state">No upcoming deliveries scheduled.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>When</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcomingDeliveries as $delivery): ?>
                        <?php
                        $scheduledAt = $delivery['scheduled_at'] ?? null;
                        $scheduledLabel = $scheduledAt ? date('M j, H:i', strtotime($scheduledAt)) : '—';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($delivery['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($scheduledLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($delivery['status'] ?? 'pending'))), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="data-card">
        <header>
            <h2>Recent Payments</h2>
            <span class="badge badge-success">Collections</span>
        </header>
        <?php if (!$recentPayments): ?>
            <div class="empty-state">No payments recorded yet.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Amount</th>
                        <th>Received</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $payment): ?>
                        <?php
                        $receivedAt = $payment['received_at'] ?? null;
                        $receivedLabel = $receivedAt ? date('M j, H:i', strtotime($receivedAt)) : '—';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($payment['invoice_number'] ?? ('INV-' . (int)$payment['invoice_id']), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small><?= htmlspecialchars($payment['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td>
                                <?php if ((float)$payment['amount_usd'] > 0): ?>
                                    $<?= number_format((float)$payment['amount_usd'], 2) ?><br>
                                <?php endif; ?>
                                <?php if ((float)$payment['amount_lbp'] > 0): ?>
                                    <small><?= number_format((float)$payment['amount_lbp']) ?> LBP</small>
                                <?php endif; ?>
                                <?php if (!(float)$payment['amount_usd'] && !(float)$payment['amount_lbp']): ?>
                                    <small>—</small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($receivedLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>
</section>

<section class="data-card">
    <header>
        <h2>Van Stock Snapshot</h2>
        <span class="badge badge-neutral">Inventory</span>
    </header>
    <div class="van-stock-summary">
        <div>
            <span class="label">SKUs</span>
            <strong><?= number_format((int)$vanStockSummary['sku_count']) ?></strong>
        </div>
        <div>
            <span class="label">Units On Hand</span>
            <strong><?= number_format((float)$vanStockSummary['total_units'], 1) ?></strong>
        </div>
        <div>
            <span class="label">Stock Value (USD)</span>
            <strong>$<?= number_format((float)$vanStockSummary['total_value_usd'], 2) ?></strong>
        </div>
    </div>

    <h3>Latest Movements</h3>
    <?php if (!$vanStockMovements): ?>
        <div class="empty-state">No van stock movements recorded.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Change</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vanStockMovements as $movement): ?>
                    <?php
                    $delta = (float)($movement['delta_qty'] ?? 0);
                    $deltaLabel = ($delta > 0 ? '+' : '') . number_format($delta, 1);
                    $movementAt = $movement['created_at'] ?? null;
                    $movementLabel = $movementAt ? date('M j, H:i', strtotime($movementAt)) : '—';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($movementLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= htmlspecialchars($movement['item_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?><br>
                            <small><?= htmlspecialchars($movement['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                        </td>
                        <td><?= $deltaLabel ?></td>
                        <td><?= htmlspecialchars($movement['reason'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php sales_portal_render_layout_end(); ?>
