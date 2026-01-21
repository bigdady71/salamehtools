<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Get current month date range
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$today = date('Y-m-d');

// Revenue this month (from paid invoices)
$revenueStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(total_usd), 0) as revenue_usd,
        COALESCE(SUM(total_lbp), 0) as revenue_lbp,
        COUNT(*) as invoice_count
    FROM invoices
    WHERE status IN ('issued', 'paid')
    AND created_at >= :start AND created_at <= :end
");
$revenueStmt->execute([':start' => $monthStart, ':end' => $monthEnd . ' 23:59:59']);
$revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);

// Outstanding receivables
$receivablesStmt = $pdo->query("
    SELECT
        COALESCE(SUM(i.total_usd), 0) - COALESCE(SUM(p.paid_usd), 0) as outstanding_usd,
        COUNT(DISTINCT i.id) as outstanding_invoices
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd
        FROM payments GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
    HAVING outstanding_usd > 0
");
$receivables = $receivablesStmt->fetch(PDO::FETCH_ASSOC) ?: ['outstanding_usd' => 0, 'outstanding_invoices' => 0];

// Pending commissions (if table exists)
$pendingCommissions = ['pending_usd' => 0, 'pending_count' => 0];
try {
    $commStmt = $pdo->query("
        SELECT
            COALESCE(SUM(commission_amount_usd), 0) as pending_usd,
            COUNT(*) as pending_count
        FROM commission_calculations
        WHERE status IN ('calculated', 'approved')
    ");
    $pendingCommissions = $commStmt->fetch(PDO::FETCH_ASSOC) ?: $pendingCommissions;
} catch (PDOException $e) {
    // Table may not exist yet
}

// Inventory value
$inventoryStmt = $pdo->query("
    SELECT
        COUNT(*) as total_products,
        COALESCE(SUM(quantity_on_hand), 0) as total_units,
        COALESCE(SUM(quantity_on_hand * COALESCE(cost_price_usd, 0)), 0) as cost_value,
        COALESCE(SUM(quantity_on_hand * COALESCE(sale_price_usd, 0)), 0) as retail_value
    FROM products
    WHERE is_active = 1
");
$inventory = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

// Recent payments
$recentPaymentsStmt = $pdo->query("
    SELECT
        p.id,
        p.amount_usd,
        p.amount_lbp,
        p.method,
        p.received_at,
        c.name as customer_name,
        i.invoice_number
    FROM payments p
    JOIN invoices i ON i.id = p.invoice_id
    JOIN orders o ON o.id = i.order_id
    JOIN customers c ON c.id = o.customer_id
    ORDER BY p.received_at DESC
    LIMIT 5
");
$recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Customers with overdue balances
$overdueStmt = $pdo->query("
    SELECT
        c.id,
        c.name,
        COALESCE(c.account_balance_usd, 0) as balance_usd
    FROM customers c
    WHERE COALESCE(c.account_balance_usd, 0) > 0
    ORDER BY c.account_balance_usd DESC
    LIMIT 5
");
$overdueCustomers = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

// Low stock items
$lowStockStmt = $pdo->query("
    SELECT id, sku, item_name, quantity_on_hand, safety_stock
    FROM products
    WHERE is_active = 1
    AND quantity_on_hand <= COALESCE(safety_stock, 10)
    ORDER BY quantity_on_hand ASC
    LIMIT 5
");
$lowStockItems = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

accounting_render_layout_start([
    'title' => 'Dashboard',
    'heading' => 'Accounting Dashboard',
    'subtitle' => 'Financial overview and quick actions',
    'active' => 'dashboard',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Revenue (This Month)</div>
        <div class="value"><?= format_currency_usd((float)$revenue['revenue_usd']) ?></div>
        <div class="sub"><?= (int)$revenue['invoice_count'] ?> invoices</div>
    </div>
    <div class="metric-card">
        <div class="label">Outstanding Receivables</div>
        <div class="value"><?= format_currency_usd((float)$receivables['outstanding_usd']) ?></div>
        <div class="sub"><?= (int)$receivables['outstanding_invoices'] ?> unpaid invoices</div>
    </div>
    <div class="metric-card">
        <div class="label">Pending Commissions</div>
        <div class="value"><?= format_currency_usd((float)$pendingCommissions['pending_usd']) ?></div>
        <div class="sub"><?= (int)$pendingCommissions['pending_count'] ?> awaiting payment</div>
    </div>
    <div class="metric-card">
        <div class="label">Inventory Value (Cost)</div>
        <div class="value"><?= format_currency_usd((float)$inventory['cost_value']) ?></div>
        <div class="sub"><?= number_format((int)$inventory['total_units']) ?> units in stock</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 24px;">
    <div class="card">
        <h2>Recent Payments</h2>
        <?php if (empty($recentPayments)): ?>
            <p class="text-muted">No recent payments</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Invoice</th>
                        <th>Amount</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?= htmlspecialchars($payment['customer_name']) ?></td>
                            <td><?= htmlspecialchars($payment['invoice_number'] ?? '-') ?></td>
                            <td><?= format_currency_usd((float)$payment['amount_usd']) ?></td>
                            <td><?= date('M j', strtotime($payment['received_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-2">
                <a href="receivables.php" class="btn btn-sm">View All Receivables</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Top Outstanding Balances</h2>
        <?php if (empty($overdueCustomers)): ?>
            <p class="text-muted">No outstanding balances</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overdueCustomers as $customer): ?>
                        <tr>
                            <td><?= htmlspecialchars($customer['name']) ?></td>
                            <td class="text-right text-danger"><?= format_currency_usd((float)$customer['balance_usd']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-2">
                <a href="customer_balances.php" class="btn btn-sm">Manage Balances</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
    <div class="card">
        <h2>Low Stock Alert</h2>
        <?php if (empty($lowStockItems)): ?>
            <p class="text-muted">All items are well stocked</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th class="text-right">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockItems as $item): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($item['sku']) ?></code></td>
                            <td><?= htmlspecialchars(substr($item['item_name'], 0, 30)) ?></td>
                            <td class="text-right">
                                <span class="badge badge-<?= $item['quantity_on_hand'] <= 0 ? 'danger' : 'warning' ?>">
                                    <?= (int)$item['quantity_on_hand'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-2">
                <a href="inventory.php" class="btn btn-sm">View Inventory</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Quick Actions</h2>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="balance_adjustment.php" class="btn">Adjust Customer Balance</a>
            <a href="commissions.php" class="btn">Calculate Commissions</a>
            <a href="reports.php" class="btn">Generate Reports</a>
            <a href="invoices.php" class="btn">View Invoices</a>
        </div>
    </div>
</div>

<?php
accounting_render_layout_end();
