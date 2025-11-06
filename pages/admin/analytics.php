<?php
/**
 * Analytics Dashboard - Daily/Weekly/Monthly Reports with Charts
 * B2B-focused analytics for sales, customers, inventory, and financial metrics
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Analytics Dashboard';

// Get period filter (default: last 30 days)
$period = $_GET['period'] ?? '30days';
$dateFrom = null;
$dateTo = date('Y-m-d');

switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        break;
    case '7days':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        break;
    case '30days':
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90days':
        $dateFrom = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'thismonth':
        $dateFrom = date('Y-m-01');
        break;
    case 'lastmonth':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'thisyear':
        $dateFrom = date('Y-01-01');
        break;
    default:
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

// ========================================
// REVENUE ANALYTICS
// ========================================

// Daily revenue trend
$revenueDaily = $pdo->prepare("
    SELECT
        DATE(i.created_at) as date,
        SUM(i.total_usd) as revenue_usd,
        SUM(i.total_lbp) as revenue_lbp,
        COUNT(*) as invoice_count
    FROM invoices i
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    GROUP BY DATE(i.created_at)
    ORDER BY date ASC
");
$revenueDaily->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$revenueDailyData = $revenueDaily->fetchAll(PDO::FETCH_ASSOC);

// Total revenue for period
$totalRevenue = $pdo->prepare("
    SELECT
        SUM(i.total_usd) as total_usd,
        SUM(i.total_lbp) as total_lbp,
        COUNT(*) as invoice_count,
        AVG(i.total_usd) as avg_invoice_usd
    FROM invoices i
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
");
$totalRevenue->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$revenue = $totalRevenue->fetch(PDO::FETCH_ASSOC);

// ========================================
// TOP CUSTOMERS
// ========================================

$topCustomers = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        COUNT(DISTINCT o.id) as order_count,
        SUM(i.total_usd) as total_revenue_usd,
        SUM(i.total_lbp) as total_revenue_lbp,
        AVG(i.total_usd) as avg_order_value_usd
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    INNER JOIN invoices i ON i.order_id = o.id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    GROUP BY c.id
    ORDER BY total_revenue_usd DESC
    LIMIT 10
");
$topCustomers->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$topCustomersData = $topCustomers->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// SALES REP PERFORMANCE
// ========================================

$salesRepPerf = $pdo->prepare("
    SELECT
        u.id,
        u.name,
        COUNT(DISTINCT o.id) as order_count,
        SUM(i.total_usd) as total_sales_usd,
        AVG(i.total_usd) as avg_deal_size_usd
    FROM users u
    INNER JOIN orders o ON o.sales_rep_id = u.id
    INNER JOIN invoices i ON i.order_id = o.id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    GROUP BY u.id
    ORDER BY total_sales_usd DESC
");
$salesRepPerf->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$salesRepData = $salesRepPerf->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// TOP PRODUCTS
// ========================================

$topProducts = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        SUM(oi.quantity) as units_sold,
        SUM(oi.quantity * oi.unit_price_usd) as revenue_usd,
        COUNT(DISTINCT oi.order_id) as order_count
    FROM products p
    INNER JOIN order_items oi ON oi.product_id = p.id
    INNER JOIN orders o ON o.id = oi.order_id
    INNER JOIN invoices i ON i.order_id = o.id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    GROUP BY p.id
    ORDER BY revenue_usd DESC
    LIMIT 10
");
$topProducts->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$topProductsData = $topProducts->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// PAYMENT ANALYTICS
// ========================================

$paymentStats = $pdo->prepare("
    SELECT
        method,
        COUNT(*) as payment_count,
        SUM(amount_usd) as total_usd,
        SUM(amount_lbp) as total_lbp
    FROM payments
    WHERE received_at >= :from AND received_at <= :to
    GROUP BY method
    ORDER BY total_usd DESC
");
$paymentStats->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$paymentData = $paymentStats->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// INVENTORY METRICS
// ========================================

$inventoryMetrics = $pdo->query("
    SELECT
        COUNT(*) as total_products,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
        SUM(CASE WHEN quantity_on_hand <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand <= min_quantity THEN 1 ELSE 0 END) as low_stock,
        SUM(quantity_on_hand * sale_price_usd) as inventory_value_usd
    FROM products
")->fetch(PDO::FETCH_ASSOC);

// ========================================
// FINANCIAL METRICS
// ========================================

// Days Sales Outstanding (DSO)
$dsoQuery = $pdo->query("
    SELECT
        AVG(DATEDIFF(COALESCE(p.received_at, CURDATE()), i.created_at)) as avg_days
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id, MAX(received_at) as received_at
        FROM payments
        GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
    AND i.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
");
$dso = round($dsoQuery->fetchColumn() ?? 0);

// Outstanding receivables
$outstandingAR = $pdo->query("
    SELECT
        COALESCE(SUM(i.total_usd - COALESCE(paid.paid_usd, 0)), 0) as outstanding_usd,
        COALESCE(SUM(i.total_lbp - COALESCE(paid.paid_lbp, 0)), 0) as outstanding_lbp,
        COUNT(*) as invoice_count
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id,
               SUM(amount_usd) AS paid_usd,
               SUM(amount_lbp) AS paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
    AND (i.total_usd - COALESCE(paid.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(paid.paid_lbp, 0) > 0.01)
")->fetch(PDO::FETCH_ASSOC);

// ========================================
// ORDER STATUS BREAKDOWN
// ========================================

$orderStatusBreakdown = $pdo->prepare("
    SELECT
        COALESCE(ose.status, 'on_hold') as status,
        COUNT(DISTINCT o.id) as order_count
    FROM orders o
    LEFT JOIN (
        SELECT ose1.order_id, ose1.status
        FROM order_status_events ose1
        INNER JOIN (
            SELECT order_id, MAX(id) as max_id
            FROM order_status_events
            GROUP BY order_id
        ) ose2 ON ose1.order_id = ose2.order_id AND ose1.id = ose2.max_id
    ) ose ON ose.order_id = o.id
    WHERE o.created_at >= :from AND o.created_at <= :to
    GROUP BY status
    ORDER BY order_count DESC
");
$orderStatusBreakdown->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$statusData = $orderStatusBreakdown->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #0c1022;
            --bg-panel: #141834;
            --bg-panel-alt: #1b2042;
            --text: #f4f6ff;
            --muted: #9aa3c7;
            --accent: #00ff88;
            --accent-2: #4a7dff;
            --danger: #ff5c7a;
            --warning: #ffd166;
            --success: #6ee7b7;
            --border: rgba(255,255,255,0.08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 2rem;
            color: var(--accent);
        }
        .period-selector {
            display: flex;
            gap: 10px;
        }
        .period-selector a {
            padding: 8px 16px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--muted);
            text-decoration: none;
            transition: all 0.2s;
        }
        .period-selector a:hover,
        .period-selector a.active {
            background: var(--accent-2);
            color: white;
        }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
        }
        .metric-label {
            font-size: 0.9rem;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
        }
        .metric-sub {
            font-size: 0.85rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .chart-container {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
        }
        .chart-title {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--text);
        }
        .table-container {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            color: var(--muted);
            font-weight: 600;
        }
        tr:hover {
            background: var(--bg-panel-alt);
        }
        .export-btn {
            padding: 10px 20px;
            background: var(--accent-2);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .export-btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Analytics Dashboard</h1>
        <div class="period-selector">
            <a href="?period=today" class="<?= $period === 'today' ? 'active' : '' ?>">Today</a>
            <a href="?period=7days" class="<?= $period === '7days' ? 'active' : '' ?>">7 Days</a>
            <a href="?period=30days" class="<?= $period === '30days' ? 'active' : '' ?>">30 Days</a>
            <a href="?period=90days" class="<?= $period === '90days' ? 'active' : '' ?>">90 Days</a>
            <a href="?period=thismonth" class="<?= $period === 'thismonth' ? 'active' : '' ?>">This Month</a>
            <a href="?period=lastmonth" class="<?= $period === 'lastmonth' ? 'active' : '' ?>">Last Month</a>
            <a href="?period=thisyear" class="<?= $period === 'thisyear' ? 'active' : '' ?>">This Year</a>
        </div>
    </div>

    <p style="color: var(--muted); margin-bottom: 20px;">
        Period: <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?>
    </p>

    <!-- KEY METRICS -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Total Revenue (USD)</div>
            <div class="metric-value">$<?= number_format($revenue['total_usd'] ?? 0, 2) ?></div>
            <div class="metric-sub"><?= number_format($revenue['invoice_count'] ?? 0) ?> invoices</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Average Invoice Value</div>
            <div class="metric-value">$<?= number_format($revenue['avg_invoice_usd'] ?? 0, 2) ?></div>
            <div class="metric-sub">USD per invoice</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Outstanding AR</div>
            <div class="metric-value">$<?= number_format($outstandingAR['outstanding_usd'] ?? 0, 2) ?></div>
            <div class="metric-sub"><?= $outstandingAR['invoice_count'] ?? 0 ?> unpaid invoices</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Days Sales Outstanding</div>
            <div class="metric-value"><?= $dso ?> days</div>
            <div class="metric-sub">Average collection time</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Inventory Value</div>
            <div class="metric-value">$<?= number_format($inventoryMetrics['inventory_value_usd'] ?? 0, 0) ?></div>
            <div class="metric-sub"><?= $inventoryMetrics['active_products'] ?? 0 ?> active products</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Low Stock Alert</div>
            <div class="metric-value"><?= $inventoryMetrics['low_stock'] ?? 0 ?></div>
            <div class="metric-sub"><?= $inventoryMetrics['out_of_stock'] ?? 0 ?> out of stock</div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="charts-grid">
        <!-- Revenue Trend Chart -->
        <div class="chart-container">
            <h3 class="chart-title">Revenue Trend</h3>
            <canvas id="revenueTrendChart"></canvas>
        </div>

        <!-- Top Customers Chart -->
        <div class="chart-container">
            <h3 class="chart-title">Top 10 Customers by Revenue</h3>
            <canvas id="topCustomersChart"></canvas>
        </div>

        <!-- Sales Rep Performance -->
        <div class="chart-container">
            <h3 class="chart-title">Sales Rep Performance</h3>
            <canvas id="salesRepChart"></canvas>
        </div>

        <!-- Order Status Breakdown -->
        <div class="chart-container">
            <h3 class="chart-title">Order Status Distribution</h3>
            <canvas id="orderStatusChart"></canvas>
        </div>
    </div>

    <!-- TOP PRODUCTS TABLE -->
    <div class="table-container">
        <h3 class="chart-title">Top 10 Products by Revenue</h3>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Units Sold</th>
                    <th>Revenue (USD)</th>
                    <th>Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topProductsData as $product): ?>
                <tr>
                    <td><?= htmlspecialchars($product['sku']) ?></td>
                    <td><?= htmlspecialchars($product['item_name']) ?></td>
                    <td><?= number_format($product['units_sold'], 2) ?></td>
                    <td>$<?= number_format($product['revenue_usd'], 2) ?></td>
                    <td><?= $product['order_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- PAYMENT METHODS TABLE -->
    <div class="table-container">
        <h3 class="chart-title">Payment Methods Breakdown</h3>
        <table>
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Transactions</th>
                    <th>Total (USD)</th>
                    <th>Total (LBP)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paymentData as $payment): ?>
                <tr>
                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $payment['method']))) ?></td>
                    <td><?= $payment['payment_count'] ?></td>
                    <td>$<?= number_format($payment['total_usd'], 2) ?></td>
                    <td>LBP <?= number_format($payment['total_lbp'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        // Revenue Trend Chart with Click Handler
        const revenueCtx = document.getElementById('revenueTrendChart').getContext('2d');
        const revenueDates = <?= json_encode(array_column($revenueDailyData, 'date')) ?>;

        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueDates,
                datasets: [{
                    label: 'Revenue (USD)',
                    data: <?= json_encode(array_column($revenueDailyData, 'revenue_usd')) ?>,
                    borderColor: '#00ff88',
                    backgroundColor: 'rgba(0, 255, 136, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { labels: { color: '#f4f6ff' } },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                return 'Click to view invoices for this date';
                            }
                        }
                    }
                },
                scales: {
                    y: { ticks: { color: '#9aa3c7' }, grid: { color: 'rgba(255,255,255,0.08)' } },
                    x: { ticks: { color: '#9aa3c7' }, grid: { color: 'rgba(255,255,255,0.08)' } }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const date = revenueDates[index];
                        // Drill down to invoices page filtered by this date
                        window.location.href = 'invoices.php?date_from=' + date + '&date_to=' + date;
                    }
                }
            }
        });

        // Top Customers Chart with Click Handler
        const customersCtx = document.getElementById('topCustomersChart').getContext('2d');
        const customerIds = <?= json_encode(array_column($topCustomersData, 'id')) ?>;

        new Chart(customersCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($topCustomersData, 'name')) ?>,
                datasets: [{
                    label: 'Revenue (USD)',
                    data: <?= json_encode(array_column($topCustomersData, 'total_revenue_usd')) ?>,
                    backgroundColor: '#4a7dff'
                }]
            },
            options: {
                responsive: true,
                indexAxis: 'y',
                plugins: {
                    legend: { labels: { color: '#f4f6ff' } },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                return 'Click to view customer orders';
                            }
                        }
                    }
                },
                scales: {
                    y: { ticks: { color: '#9aa3c7' }, grid: { color: 'rgba(255,255,255,0.08)' } },
                    x: { ticks: { color: '#9aa3c7' }, grid: { color: 'rgba(255,255,255,0.08)' } }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const customerId = customerIds[index];
                        // Drill down to orders page filtered by customer
                        window.location.href = 'orders.php?customer=' + customerId;
                    }
                }
            }
        });

        // Sales Rep Performance Chart with Click Handler
        const salesRepCtx = document.getElementById('salesRepChart').getContext('2d');
        const salesRepIds = <?= json_encode(array_column($salesRepData, 'id')) ?>;

        new Chart(salesRepCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($salesRepData, 'name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($salesRepData, 'total_sales_usd')) ?>,
                    backgroundColor: ['#00ff88', '#4a7dff', '#ffd166', '#ff5c7a', '#6ee7b7']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { labels: { color: '#f4f6ff' } },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                return 'Click to view sales rep orders';
                            }
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const salesRepId = salesRepIds[index];
                        // Drill down to orders page filtered by sales rep
                        window.location.href = 'orders.php?sales_rep=' + salesRepId;
                    }
                }
            }
        });

        // Order Status Chart with Click Handler
        const statusCtx = document.getElementById('orderStatusChart').getContext('2d');
        const statuses = <?= json_encode(array_column($statusData, 'status')) ?>;

        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: statuses,
                datasets: [{
                    data: <?= json_encode(array_column($statusData, 'order_count')) ?>,
                    backgroundColor: ['#ffd166', '#4a7dff', '#00ff88', '#6ee7b7', '#ff5c7a']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { labels: { color: '#f4f6ff' } },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                return 'Click to view orders with this status';
                            }
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const status = statuses[index];
                        // Drill down to orders page filtered by status
                        window.location.href = 'orders.php?status=' + encodeURIComponent(status);
                    }
                }
            }
        });
    </script>
</body>
</html>
