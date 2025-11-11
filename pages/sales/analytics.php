<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/admin_page.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$navLinks = sales_portal_nav_links();
$title = 'Analytics & Statistics';

// Get sales rep ID
$salesRepId = (int)$user['id'];

// Initialize period filter (default: current month)
$period = $_GET['period'] ?? 'month';
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;

// Calculate date ranges
$dateFilter = '';
$dateParams = ['rep_id' => $salesRepId];

switch ($period) {
    case 'today':
        $dateFilter = "AND DATE(o.created_at) = CURDATE()";
        $periodLabel = 'Today';
        break;
    case 'week':
        $dateFilter = "AND YEARWEEK(o.created_at, 1) = YEARWEEK(CURDATE(), 1)";
        $periodLabel = 'This Week';
        break;
    case 'month':
        $dateFilter = "AND YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())";
        $periodLabel = 'This Month';
        break;
    case 'quarter':
        $dateFilter = "AND YEAR(o.created_at) = YEAR(CURDATE()) AND QUARTER(o.created_at) = QUARTER(CURDATE())";
        $periodLabel = 'This Quarter';
        break;
    case 'year':
        $dateFilter = "AND YEAR(o.created_at) = YEAR(CURDATE())";
        $periodLabel = 'This Year';
        break;
    case 'custom':
        if ($customStart && $customEnd) {
            $dateFilter = "AND DATE(o.created_at) BETWEEN :start_date AND :end_date";
            $dateParams['start_date'] = $customStart;
            $dateParams['end_date'] = $customEnd;
            $periodLabel = date('M d, Y', strtotime($customStart)) . ' - ' . date('M d, Y', strtotime($customEnd));
        } else {
            $dateFilter = "AND YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())";
            $periodLabel = 'This Month';
        }
        break;
    default:
        $dateFilter = "AND YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())";
        $periodLabel = 'This Month';
}

try {
    // ============================================================
    // 1. REVENUE METRICS
    // ============================================================

    $revenueStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(o.total_usd), 0) as total_revenue_usd,
            COALESCE(SUM(o.total_lbp), 0) as total_revenue_lbp,
            COALESCE(AVG(o.total_usd), 0) as avg_order_value_usd,
            COUNT(DISTINCT o.customer_id) as unique_customers
        FROM orders o
        WHERE o.sales_rep_id = :rep_id
        {$dateFilter}
    ");
    $revenueStmt->execute($dateParams);
    $revenueMetrics = $revenueStmt->fetch(PDO::FETCH_ASSOC);

    // ============================================================
    // 2. INVOICE & PAYMENT METRICS
    // ============================================================

    $invoiceStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_usd), 0) as invoiced_usd,
            COALESCE(SUM(i.total_lbp), 0) as invoiced_lbp,
            COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_usd ELSE 0 END), 0) as paid_usd,
            COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_lbp ELSE 0 END), 0) as paid_lbp,
            COALESCE(SUM(CASE WHEN i.status = 'issued' THEN i.total_usd ELSE 0 END), 0) as outstanding_usd,
            COALESCE(SUM(CASE WHEN i.status = 'issued' THEN i.total_lbp ELSE 0 END), 0) as outstanding_lbp
        FROM invoices i
        INNER JOIN orders o ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND i.status IN ('issued', 'paid')
        {$dateFilter}
    ");
    $invoiceStmt->execute($dateParams);
    $invoiceMetrics = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

    // Calculate collection rate
    $collectionRate = 0;
    if ($invoiceMetrics['invoiced_usd'] > 0) {
        $collectionRate = ($invoiceMetrics['paid_usd'] / $invoiceMetrics['invoiced_usd']) * 100;
    }

    // ============================================================
    // 3. TOP SELLING PRODUCTS
    // ============================================================

    $topProductsStmt = $pdo->prepare("
        SELECT
            p.item_name,
            p.sku,
            SUM(oi.quantity) as total_qty,
            COALESCE(SUM(oi.quantity * oi.unit_price_usd), 0) as total_revenue_usd,
            COUNT(DISTINCT oi.order_id) as order_count
        FROM order_items oi
        INNER JOIN products p ON oi.product_id = p.id
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        {$dateFilter}
        GROUP BY p.id, p.item_name, p.sku
        ORDER BY total_revenue_usd DESC
        LIMIT 10
    ");
    $topProductsStmt->execute($dateParams);
    $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 4. TOP CUSTOMERS BY REVENUE
    // ============================================================

    $topCustomersStmt = $pdo->prepare("
        SELECT
            c.customer_name,
            c.phone,
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(o.total_usd), 0) as total_revenue_usd,
            COALESCE(SUM(o.total_lbp), 0) as total_revenue_lbp,
            MAX(o.created_at) as last_order_date
        FROM orders o
        INNER JOIN customers c ON o.customer_id = c.id
        WHERE o.sales_rep_id = :rep_id
        {$dateFilter}
        GROUP BY c.id, c.customer_name, c.phone
        ORDER BY total_revenue_usd DESC
        LIMIT 10
    ");
    $topCustomersStmt->execute($dateParams);
    $topCustomers = $topCustomersStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 5. ORDER STATUS BREAKDOWN
    // ============================================================

    $orderStatusStmt = $pdo->prepare("
        SELECT
            COALESCE(ose.status, 'pending') as status,
            COUNT(DISTINCT o.id) as count,
            COALESCE(SUM(o.total_usd), 0) as total_usd
        FROM orders o
        LEFT JOIN (
            SELECT DISTINCT order_id, status
            FROM order_status_events ose1
            WHERE created_at = (
                SELECT MAX(created_at)
                FROM order_status_events ose2
                WHERE ose2.order_id = ose1.order_id
            )
        ) ose ON ose.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        {$dateFilter}
        GROUP BY ose.status
        ORDER BY count DESC
    ");
    $orderStatusStmt->execute($dateParams);
    $orderStatusBreakdown = $orderStatusStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 6. DAILY PERFORMANCE TREND (Last 30 days for chart)
    // ============================================================

    $trendStmt = $pdo->prepare("
        SELECT
            DATE(o.created_at) as order_date,
            COUNT(DISTINCT o.id) as daily_orders,
            COALESCE(SUM(o.total_usd), 0) as daily_revenue_usd
        FROM orders o
        WHERE o.sales_rep_id = :rep_id
        AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(o.created_at)
        ORDER BY order_date ASC
    ");
    $trendStmt->execute(['rep_id' => $salesRepId]);
    $dailyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 7. CUSTOMER ACTIVITY METRICS
    // ============================================================

    $customerActivityStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT c.id) as total_assigned_customers,
            COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN c.id END) as active_customers,
            COUNT(DISTINCT CASE WHEN o.id IS NULL THEN c.id END) as inactive_customers
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id {$dateFilter}
        WHERE c.assigned_sales_rep_id = :rep_id
    ");
    $customerActivityStmt->execute($dateParams);
    $customerActivity = $customerActivityStmt->fetch(PDO::FETCH_ASSOC);

    // Calculate penetration rate
    $penetrationRate = 0;
    if ($customerActivity['total_assigned_customers'] > 0) {
        $penetrationRate = ($customerActivity['active_customers'] / $customerActivity['total_assigned_customers']) * 100;
    }

    // ============================================================
    // 8. VAN STOCK SUMMARY
    // ============================================================

    $vanStockStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT s.product_id) as stocked_products,
            COALESCE(SUM(s.qty_on_hand), 0) as total_units,
            COALESCE(SUM(s.qty_on_hand * p.sale_price_usd), 0) as inventory_value_usd
        FROM s_stock s
        INNER JOIN products p ON s.product_id = p.id
        WHERE s.salesperson_id = :rep_id
        AND s.qty_on_hand > 0
    ");
    $vanStockStmt->execute(['rep_id' => $salesRepId]);
    $vanStock = $vanStockStmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Analytics error: " . $e->getMessage());
    $revenueMetrics = [
        'order_count' => 0,
        'total_revenue_usd' => 0,
        'total_revenue_lbp' => 0,
        'avg_order_value_usd' => 0,
        'unique_customers' => 0
    ];
    $invoiceMetrics = [
        'invoice_count' => 0,
        'invoiced_usd' => 0,
        'invoiced_lbp' => 0,
        'paid_usd' => 0,
        'paid_lbp' => 0,
        'outstanding_usd' => 0,
        'outstanding_lbp' => 0
    ];
    $collectionRate = 0;
    $topProducts = [];
    $topCustomers = [];
    $orderStatusBreakdown = [];
    $dailyTrend = [];
    $customerActivity = [
        'total_assigned_customers' => 0,
        'active_customers' => 0,
        'inactive_customers' => 0
    ];
    $penetrationRate = 0;
    $vanStock = [
        'stocked_products' => 0,
        'total_units' => 0,
        'inventory_value_usd' => 0
    ];
}

// Prepare chart data for JavaScript
$trendLabels = array_map(function($row) {
    return date('M d', strtotime($row['order_date']));
}, $dailyTrend);

$trendRevenue = array_map(function($row) {
    return (float)$row['daily_revenue_usd'];
}, $dailyTrend);

$trendOrders = array_map(function($row) {
    return (int)$row['daily_orders'];
}, $dailyTrend);

$statusLabels = array_map(function($row) {
    return ucfirst($row['status'] ?? 'pending');
}, $orderStatusBreakdown);

$statusCounts = array_map(function($row) {
    return (int)$row['count'];
}, $orderStatusBreakdown);

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Your performance metrics and sales insights for ' . $periodLabel,
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'analytics',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .metric-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.25rem;
        }
        .metric-secondary {
            font-size: 0.875rem;
            color: #6b7280;
        }
        .metric-card.revenue { border-left: 4px solid #10b981; }
        .metric-card.orders { border-left: 4px solid #3b82f6; }
        .metric-card.customers { border-left: 4px solid #8b5cf6; }
        .metric-card.collection { border-left: 4px solid #f59e0b; }

        .filter-bar {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid #e5e7eb;
        }
        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .filter-group label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
        }
        .filter-group select,
        .filter-group input {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .btn-filter {
            padding: 0.5rem 1.5rem;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            font-size: 0.875rem;
        }
        .btn-filter:hover {
            background: #1d4ed8;
        }

        .chart-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid #e5e7eb;
        }
        .chart-section h3 {
            margin: 0 0 1.5rem 0;
            font-size: 1.125rem;
            font-weight: 600;
            color: #111827;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        .data-table th {
            background: #f9fafb;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        .data-table td {
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
        }
        .data-table tbody tr:hover {
            background: #f9fafb;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-delivered { background: #d1fae5; color: #065f46; }
        .status-on_hold { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-pending { background: #e5e7eb; color: #374151; }

        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        @media (max-width: 1024px) {
            .two-column-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>',
]);
?>

<!-- Period Filter -->
<div class="filter-bar">
    <form method="GET" action="">
        <div class="filter-row">
            <div class="filter-group">
                <label for="period">Time Period</label>
                <select name="period" id="period" onchange="toggleCustomDates()">
                    <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="quarter" <?= $period === 'quarter' ? 'selected' : '' ?>>This Quarter</option>
                    <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>This Year</option>
                    <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                </select>
            </div>

            <div class="filter-group" id="custom-dates" style="display: <?= $period === 'custom' ? 'flex' : 'none' ?>; gap: 0.5rem;">
                <div>
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($customStart ?? '') ?>">
                </div>
                <div>
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($customEnd ?? '') ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn-filter">Apply Filter</button>
            </div>
        </div>
    </form>
</div>

<script>
function toggleCustomDates() {
    const period = document.getElementById('period').value;
    const customDates = document.getElementById('custom-dates');
    customDates.style.display = period === 'custom' ? 'flex' : 'none';
}
</script>

<!-- Key Metrics Cards -->
<div class="analytics-grid">
    <div class="metric-card revenue">
        <div class="metric-label">Total Revenue</div>
        <div class="metric-value">$<?= number_format($revenueMetrics['total_revenue_usd'], 2) ?></div>
        <div class="metric-secondary"><?= number_format($revenueMetrics['total_revenue_lbp'], 0) ?> LBP</div>
    </div>

    <div class="metric-card orders">
        <div class="metric-label">Total Orders</div>
        <div class="metric-value"><?= number_format($revenueMetrics['order_count']) ?></div>
        <div class="metric-secondary">Avg: $<?= number_format($revenueMetrics['avg_order_value_usd'], 2) ?></div>
    </div>

    <div class="metric-card customers">
        <div class="metric-label">Active Customers</div>
        <div class="metric-value"><?= number_format($revenueMetrics['unique_customers']) ?></div>
        <div class="metric-secondary"><?= number_format($penetrationRate, 1) ?>% penetration</div>
    </div>

    <div class="metric-card collection">
        <div class="metric-label">Collection Rate</div>
        <div class="metric-value"><?= number_format($collectionRate, 1) ?>%</div>
        <div class="metric-secondary">$<?= number_format($invoiceMetrics['outstanding_usd'], 2) ?> outstanding</div>
    </div>
</div>

<!-- Invoice & Payment Summary -->
<div class="analytics-grid">
    <div class="metric-card">
        <div class="metric-label">Invoices Issued</div>
        <div class="metric-value"><?= number_format($invoiceMetrics['invoice_count']) ?></div>
        <div class="metric-secondary">$<?= number_format($invoiceMetrics['invoiced_usd'], 2) ?> total</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Collected Payments</div>
        <div class="metric-value">$<?= number_format($invoiceMetrics['paid_usd'], 2) ?></div>
        <div class="metric-secondary"><?= number_format($invoiceMetrics['paid_lbp'], 0) ?> LBP</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Van Inventory</div>
        <div class="metric-value"><?= number_format($vanStock['total_units']) ?></div>
        <div class="metric-secondary">$<?= number_format($vanStock['inventory_value_usd'], 2) ?> value</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Customer Coverage</div>
        <div class="metric-value"><?= number_format($customerActivity['active_customers']) ?>/<?= number_format($customerActivity['total_assigned_customers']) ?></div>
        <div class="metric-secondary"><?= number_format($customerActivity['inactive_customers']) ?> inactive</div>
    </div>
</div>

<!-- Charts Section -->
<div class="two-column-grid">
    <div class="chart-section">
        <h3>30-Day Revenue Trend</h3>
        <div class="chart-container">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </div>

    <div class="chart-section">
        <h3>Order Status Distribution</h3>
        <div class="chart-container">
            <canvas id="orderStatusChart"></canvas>
        </div>
    </div>
</div>

<!-- Top Products -->
<div class="chart-section">
    <h3>Top 10 Products by Revenue (<?= htmlspecialchars($periodLabel) ?>)</h3>
    <?php if (count($topProducts) > 0): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Product Name</th>
                    <th>SKU</th>
                    <th>Quantity Sold</th>
                    <th>Total Revenue (USD)</th>
                    <th>Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topProducts as $idx => $product): ?>
                    <tr>
                        <td><strong><?= $idx + 1 ?></strong></td>
                        <td><?= htmlspecialchars($product['item_name']) ?></td>
                        <td><?= htmlspecialchars($product['sku']) ?></td>
                        <td><?= number_format($product['total_qty']) ?></td>
                        <td>$<?= number_format($product['total_revenue_usd'], 2) ?></td>
                        <td><?= number_format($product['order_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <p>No product sales data for the selected period.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Top Customers -->
<div class="chart-section">
    <h3>Top 10 Customers by Revenue (<?= htmlspecialchars($periodLabel) ?>)</h3>
    <?php if (count($topCustomers) > 0): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Customer Name</th>
                    <th>Phone</th>
                    <th>Orders</th>
                    <th>Revenue (USD)</th>
                    <th>Revenue (LBP)</th>
                    <th>Last Order</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topCustomers as $idx => $customer): ?>
                    <tr>
                        <td><strong><?= $idx + 1 ?></strong></td>
                        <td><?= htmlspecialchars($customer['customer_name']) ?></td>
                        <td><?= htmlspecialchars($customer['phone']) ?></td>
                        <td><?= number_format($customer['order_count']) ?></td>
                        <td>$<?= number_format($customer['total_revenue_usd'], 2) ?></td>
                        <td><?= number_format($customer['total_revenue_lbp'], 0) ?></td>
                        <td><?= date('M d, Y', strtotime($customer['last_order_date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <p>No customer data for the selected period.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Order Status Breakdown Table -->
<div class="chart-section">
    <h3>Order Status Breakdown (<?= htmlspecialchars($periodLabel) ?>)</h3>
    <?php if (count($orderStatusBreakdown) > 0): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Order Count</th>
                    <th>Total Value (USD)</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalOrders = array_sum(array_column($orderStatusBreakdown, 'count'));
                foreach ($orderStatusBreakdown as $status):
                    $percentage = $totalOrders > 0 ? ($status['count'] / $totalOrders * 100) : 0;
                    $statusClass = 'status-' . strtolower(str_replace(' ', '_', $status['status'] ?? 'pending'));
                ?>
                    <tr>
                        <td>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= htmlspecialchars(ucfirst($status['status'] ?? 'pending')) ?>
                            </span>
                        </td>
                        <td><?= number_format($status['count']) ?></td>
                        <td>$<?= number_format($status['total_usd'], 2) ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <p>No orders for the selected period.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Chart.js Scripts -->
<script>
// Revenue Trend Chart
const revenueTrendCtx = document.getElementById('revenueTrendChart');
if (revenueTrendCtx) {
    new Chart(revenueTrendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendLabels) ?>,
            datasets: [{
                label: 'Revenue (USD)',
                data: <?= json_encode($trendRevenue) ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// Order Status Chart
const orderStatusCtx = document.getElementById('orderStatusChart');
if (orderStatusCtx) {
    new Chart(orderStatusCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($statusLabels) ?>,
            datasets: [{
                data: <?= json_encode($statusCounts) ?>,
                backgroundColor: [
                    '#10b981',
                    '#f59e0b',
                    '#3b82f6',
                    '#8b5cf6',
                    '#ef4444',
                    '#6b7280'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'right'
                }
            }
        }
    });
}
</script>

<?php sales_portal_render_layout_end(); ?>
