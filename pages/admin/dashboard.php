<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/CacheManager.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$title = 'Admin · Dashboard';
$pdo = db();
$errors = [];
$notices = [];

// Initialize cache manager (using file-based caching)
$cache = new CacheManager('file');

$scalar = static function (string $label, string $sql, array $params = []) use ($pdo, &$errors) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === null ? 0 : $value;
    } catch (PDOException $e) {
        $errors[] = "{$label}: {$e->getMessage()}";
        return 0;
    }
};

$fetchAll = static function (string $label, string $sql, array $params = []) use ($pdo, &$errors) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errors[] = "{$label}: {$e->getMessage()}";
        return [];
    }
};

$latestStatusSubquery = "
    SELECT ose.order_id, ose.status, ose.created_at
    FROM order_status_events ose
    JOIN (
        SELECT order_id, MAX(id) AS max_id
        FROM order_status_events
        WHERE status <> 'invoice_created'
        GROUP BY order_id
    ) latest ON latest.order_id = ose.order_id AND latest.max_id = ose.id
    WHERE ose.status <> 'invoice_created'
";

// Cache dashboard metrics for 5 minutes (300 seconds)
$dashboardMetrics = $cache->remember('dashboard_metrics', 300, function() use ($scalar, $latestStatusSubquery) {
    return [
        'ordersToday' => (int)$scalar('Orders today', "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURRENT_DATE()"),
        'openOrders' => (int)$scalar(
            'Open orders',
            "
                SELECT COUNT(*)
                FROM orders o
                LEFT JOIN ({$latestStatusSubquery}) current_status ON current_status.order_id = o.id
                WHERE current_status.status IS NULL
                   OR current_status.status NOT IN ('delivered','cancelled','returned')
            "
        ),
        'awaitingApproval' => (int)$scalar(
            'Awaiting approval',
            "
                SELECT COUNT(*)
                FROM orders o
                LEFT JOIN ({$latestStatusSubquery}) current_status ON current_status.order_id = o.id
                WHERE COALESCE(current_status.status, 'on_hold') = 'on_hold'
            "
        ),
        'inTransit' => (int)$scalar(
            'In transit',
            "
                SELECT COUNT(*)
                FROM orders o
                JOIN ({$latestStatusSubquery}) current_status ON current_status.order_id = o.id
                WHERE current_status.status = 'in_transit'
            "
        ),
        'deliveriesToday' => (int)$scalar(
            'Deliveries today',
            "SELECT COUNT(*) FROM deliveries WHERE DATE(scheduled_at) = CURRENT_DATE()"
        ),
    ];
});

// Extract cached values
$ordersToday = $dashboardMetrics['ordersToday'];
$openOrders = $dashboardMetrics['openOrders'];
$awaitingApproval = $dashboardMetrics['awaitingApproval'];
$inTransit = $dashboardMetrics['inTransit'];
$deliveriesToday = $dashboardMetrics['deliveriesToday'];

$openInvoicesUsd = 0.0;
$openInvoicesLbp = 0.0;
try {
    $stmt = $pdo->query("
        SELECT
            COALESCE(SUM(balance_usd), 0) AS usd,
            COALESCE(SUM(balance_lbp), 0) AS lbp
        FROM v_invoice_balances
        WHERE balance_usd > 0 OR balance_lbp > 0
    ");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $openInvoicesUsd = (float)$row['usd'];
        $openInvoicesLbp = (float)$row['lbp'];
    }
} catch (PDOException $e) {
    $errorCode = $e->errorInfo[0] ?? null;
    if ($errorCode === '42S02') {
        $notices[] = 'v_invoice_balances view not found; using fallback invoice totals.';
    } else {
        $errors[] = "Invoice balances: {$e->getMessage()}";
    }
    try {
        $stmt = $pdo->query("
            SELECT
                COALESCE(SUM(GREATEST(i.total_usd - IFNULL(paid.paid_usd, 0), 0)), 0) AS usd,
                COALESCE(SUM(GREATEST(i.total_lbp - IFNULL(paid.paid_lbp, 0), 0)), 0) AS lbp
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id,
                       SUM(amount_usd) AS paid_usd,
                       SUM(amount_lbp) AS paid_lbp
                FROM payments
                GROUP BY invoice_id
            ) paid ON paid.invoice_id = i.id
            WHERE i.status <> 'voided'
        ");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $openInvoicesUsd = (float)$row['usd'];
            $openInvoicesLbp = (float)$row['lbp'];
        }
    } catch (PDOException $fallback) {
        $errors[] = "Invoice fallback: {$fallback->getMessage()}";
    }
}

$lowStock = $fetchAll(
    'Low stock',
    "
        SELECT id, sku, item_name, quantity_on_hand, min_quantity
        FROM products
        WHERE is_active = 1
          AND quantity_on_hand <= GREATEST(min_quantity, 5)
        ORDER BY quantity_on_hand ASC
        LIMIT 8
    "
);

$latestOrders = $fetchAll(
    'Latest orders',
    "
        SELECT o.id,
               o.order_number,
               o.created_at,
               o.total_usd,
               o.total_lbp,
               c.name AS customer_name,
               sr.name AS sales_rep_name,
               COALESCE(current_status.status, 'on_hold') AS status,
               current_status.created_at AS status_changed_at
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users sr ON sr.id = o.sales_rep_id
        LEFT JOIN ({$latestStatusSubquery}) current_status ON current_status.order_id = o.id
        ORDER BY o.created_at DESC
        LIMIT 8
    "
);

$recentPayments = $fetchAll(
    'Recent payments',
    "
        SELECT p.id,
               p.invoice_id,
               p.amount_usd,
               p.amount_lbp,
               p.method,
               p.received_at,
               p.created_at,
               i.invoice_number,
               u.name AS received_by
        FROM payments p
        LEFT JOIN invoices i ON i.id = p.invoice_id
        LEFT JOIN users u ON u.id = p.received_by_user_id
        ORDER BY p.received_at DESC, p.id DESC
        LIMIT 6
    "
);

$upcomingDeliveries = $fetchAll(
    'Upcoming deliveries',
    "
        SELECT d.id,
               d.order_id,
               d.status,
               d.scheduled_at,
               o.order_number,
               c.name AS customer_name,
               driver.name AS driver_name
        FROM deliveries d
        LEFT JOIN orders o ON o.id = d.order_id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users driver ON driver.id = d.driver_user_id
        WHERE d.status IN ('scheduled','preparing','ready')
        ORDER BY d.scheduled_at ASC
        LIMIT 6
    "
);

function admin_dashboard_status_meta(?string $status): array
{
    $map = [
        'on_hold'    => ['Awaiting approval', 'badge-warn'],
        'approved'   => ['Approved', 'badge-info'],
        'preparing'  => ['Preparing', 'badge-info'],
        'ready'      => ['Ready for dispatch', 'badge-info'],
        'in_transit' => ['In transit', 'badge-primary'],
        'delivered'  => ['Delivered', 'badge-success'],
        'cancelled'  => ['Cancelled', 'badge-danger'],
        'returned'   => ['Returned', 'badge-danger'],
    ];
    $key = $status ?? 'on_hold';
    return $map[$key] ?? ['Unknown', 'badge-neutral'];
}

function admin_dashboard_delivery_meta(?string $status): array
{
    $map = [
        'scheduled' => ['Scheduled', 'badge-info'],
        'preparing' => ['Preparing', 'badge-primary'],
        'ready'     => ['Ready', 'badge-primary'],
        'in_transit'=> ['In transit', 'badge-primary'],
        'delivered' => ['Delivered', 'badge-success'],
        'failed'    => ['Failed', 'badge-danger'],
        'cancelled' => ['Cancelled', 'badge-danger'],
    ];
    $key = $status ?? 'scheduled';
    return $map[$key] ?? ['Unknown', 'badge-neutral'];
}

$now = new DateTimeImmutable('now');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        :root {
            --bg: #f3f4f6;
            --bg-panel: #ffffff;
            --bg-panel-alt: #f9fafc;
            --text: #111827;
            --muted: #6b7280;
            --accent: #1f6feb;
            --accent-2: #0ea5e9;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --neutral: #9ca3af;
            --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }
        a { color: inherit; text-decoration: none; }
        .layout {
            display: flex;
            flex: 1;
        }
        .sidebar {
            width: 240px;
            background: #ffffff;
            border-right: 1px solid var(--border);
            padding: 24px 18px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
        }
        .brand {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: .04em;
            color: var(--accent);
        }
        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .nav-links a {
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--muted);
            font-size: 0.95rem;
            transition: background .2s, color .2s;
        }
        .nav-links a:hover {
            background: #f3f4f6;
            color: var(--text);
        }
        .nav-links a.active {
            background: var(--accent);
            color: #fff;
            font-weight: 600;
        }
        .user-card {
            margin-top: auto;
            padding: 12px;
            border-radius: 10px;
            background: var(--bg-panel-alt);
            border: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .user-card strong {
            display: block;
            font-size: 1rem;
            color: var(--text);
        }
        .main {
            flex: 1;
            padding: 32px;
            display: flex;
            flex-direction: column;
            gap: 32px;
            margin-left: 240px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header h1 {
            margin: 0;
            font-size: 2rem;
        }
        .page-header .sub {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .chip {
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            font-size: 0.9rem;
            color: var(--muted);
        }
        .grid {
            display: grid;
            gap: 18px;
        }
        .grid.metrics {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
        .metric-card {
            background: var(--bg-panel);
            border-radius: 16px;
            padding: 18px 20px;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }
        .metric-card h3 {
            margin: 0;
            font-size: 0.95rem;
            color: var(--muted);
            font-weight: 600;
        }
        .metric-value {
            font-size: 2.2rem;
            font-weight: 700;
        }
        .metric-sub {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .metric-card.accent::after {
            content: '';
            position: absolute;
            inset: auto -40% -40% auto;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(74,125,255,0.35), transparent 70%);
            transform: rotate(25deg);
        }
        .metric-card.accent-green::after {
            background: radial-gradient(circle, rgba(0,255,136,0.35), transparent 70%);
        }
        .metric-card.accent-gold::after {
            background: radial-gradient(circle, rgba(255,209,102,0.4), transparent 70%);
        }
        .metric-card span.small {
            font-size: 1rem;
            display: block;
        }
        .panels {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        .panel {
            background: var(--bg-panel);
            border-radius: 18px;
            border: 1px solid var(--border);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        .panel header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .panel header h2 {
            margin: 0;
            font-size: 1.1rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        thead th {
            text-align: left;
            padding: 10px 0;
            font-weight: 600;
            color: var(--muted);
        }
        tbody td {
            padding: 10px 0;
            border-top: 1px solid var(--border);
        }
        tbody tr:first-child td {
            border-top: 0;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .badge-info { background: rgba(31, 111, 235, 0.1); color: #1f6feb; }
        .badge-primary { background: rgba(31, 111, 235, 0.15); color: #1d4ed8; }
        .badge-success { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
        .badge-warn { background: rgba(245, 158, 11, 0.15); color: #d97706; }
        .badge-neutral { background: rgba(156, 163, 175, 0.15); color: #4b5563; }
        .alert {
            border-radius: 12px;
            padding: 14px 18px;
            border: 1px solid;
            font-size: 0.9rem;
        }
        .alert.error {
            background: rgba(239, 68, 68, 0.08);
            border-color: rgba(239, 68, 68, 0.3);
            color: #b91c1c;
        }
        .alert.notice {
            background: rgba(31, 111, 235, 0.08);
            border-color: rgba(31, 111, 235, 0.3);
            color: #1d4ed8;
        }
        .alert ul {
            margin: 6px 0 0;
            padding-left: 20px;
        }
        .empty {
            font-size: 0.9rem;
            color: var(--muted);
            padding: 12px 0;
        }
        @media (max-width: 1024px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: auto;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                position: static;
                overflow-y: visible;
            }
            .main {
                margin-left: 0;
            }
            .nav-links {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }
            .user-card {
                margin-top: 0;
            }
            .panels {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 640px) {
            .main {
                padding: 24px 18px 40px;
            }
            .metric-value {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div>
                <div class="brand">Salameh Tools</div>
                <div style="margin-top:6px;font-size:0.85rem;color:var(--muted)">Admin Control Center</div>
            </div>
            <nav class="nav-links">
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="users.php">Users</a>
                <a href="products.php">Products</a>
                <a href="orders.php">Orders</a>
                <a href="invoices.php">Invoices</a>
                <a href="customers.php">Customers</a>
                <a href="sales_reps.php">Sales Reps</a>
                <a href="van_stock_overview.php">Van Stock</a>
                <a href="sales_rep_stock_adjustment.php">Rep Stock Auth</a>
                <a href="receivables.php">Receivables</a>
                <a href="expenses.php">Expenses</a>
                <a href="cash_refund_approvals.php">Cash Refunds</a>
                <a href="warehouse_stock.php">Warehouse</a>
                <a href="analytics.php">Analytics</a>
                <a href="stats.php">Statistics</a>
                <a href="settings.php">Settings</a>
            </nav>
            <div class="user-card">
                <span style="color:var(--muted);">Signed in as</span>
                <strong><?= htmlspecialchars($user['name'] ?? 'Admin') ?></strong>
                <span style="font-size:0.85rem;color:var(--muted);"><?= htmlspecialchars($user['role'] ?? '') ?></span>
                <a href="../logout.php" style="display:block;margin-top:12px;padding:8px 12px;background:rgba(239,68,68,0.1);color:#dc2626;border-radius:8px;text-align:center;font-weight:600;font-size:0.85rem;border:1px solid rgba(239,68,68,0.2);">Logout</a>
            </div>
        </aside>
        <div class="main">
            <header class="page-header">
                <div>
                    <h1>Admin Dashboard</h1>
                    <div class="sub">Realtime snapshot · <?= htmlspecialchars($now->format('M j, Y · H:i')) ?></div>
                </div>
                <div class="chip">Healthy operations · <?= htmlspecialchars((string)$openOrders) ?> orders in flight</div>
            </header>

            <?php if ($errors): ?>
                <div class="alert error">
                    <strong>Heads up:</strong> Some dashboard data failed to load.
                    <ul>
                        <?php foreach ($errors as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($notices): ?>
                <div class="alert notice">
                    <strong>Note:</strong>
                    <ul>
                        <?php foreach ($notices as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="grid metrics">
                <article class="metric-card accent">
                    <h3>New orders (today)</h3>
                    <div class="metric-value"><?= number_format($ordersToday) ?></div>
                    <div class="metric-sub">Submitted since midnight</div>
                </article>
                <article class="metric-card accent-gold">
                    <h3>Awaiting approval</h3>
                    <div class="metric-value"><?= number_format($awaitingApproval) ?></div>
                    <div class="metric-sub">Orders needing review</div>
                </article>
                <article class="metric-card accent">
                    <h3>In transit</h3>
                    <div class="metric-value"><?= number_format($inTransit) ?></div>
                    <div class="metric-sub">Drivers currently on the road</div>
                </article>
                <article class="metric-card accent-green">
                    <h3>Deliveries today</h3>
                    <div class="metric-value"><?= number_format($deliveriesToday) ?></div>
                    <div class="metric-sub">Scheduled drop-offs</div>
                </article>
                <article class="metric-card accent-gold">
                    <h3>Outstanding invoices</h3>
                    <div class="metric-value">
                        <span class="small">USD <?= number_format($openInvoicesUsd, 2) ?></span>
                    </div>
                    <div class="metric-sub">LBP <?= number_format($openInvoicesLbp, 0) ?></div>
                </article>
                <article class="metric-card accent">
                    <h3>Orders in progress</h3>
                    <div class="metric-value"><?= number_format($openOrders) ?></div>
                    <div class="metric-sub">Excludes delivered / closed</div>
                </article>
            </section>

            <section class="panels">
                <article class="panel">
                    <header>
                        <h2>Latest orders</h2>
                        <span class="metric-sub">Most recent eight orders</span>
                    </header>
                    <?php if (!$latestOrders): ?>
                        <div class="empty">No orders recorded yet.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($latestOrders as $order): ?>
                                    <?php
                                    $statusMeta = admin_dashboard_status_meta($order['status'] ?? null);
                                    $orderNumber = $order['order_number'] ?: sprintf('Order #%04d', (int)$order['id']);
                                    $total = '';
                                    if ((float)$order['total_usd'] > 0) {
                                        $total = 'USD ' . number_format((float)$order['total_usd'], 2);
                                    } elseif ((float)$order['total_lbp'] > 0) {
                                        $total = 'LBP ' . number_format((float)$order['total_lbp'], 0);
                                    } else {
                                        $total = '—';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($orderNumber) ?></td>
                                        <td><?= htmlspecialchars($order['customer_name'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($total) ?></td>
                                        <td><span class="badge <?= htmlspecialchars($statusMeta[1]) ?>"><?= htmlspecialchars($statusMeta[0]) ?></span></td>
                                        <td><?= htmlspecialchars(date('M j, H:i', strtotime($order['created_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </article>
                <aside class="panel">
                    <header>
                        <h2>Low stock alerts</h2>
                        <span class="metric-sub">Qty ≤ safety threshold</span>
                    </header>
                    <?php if (!$lowStock): ?>
                        <div class="empty">No low stock alerts.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th style="text-align:right;">On hand</th>
                                    <th style="text-align:right;">Min</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lowStock as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product['item_name']) ?></td>
                                        <td style="text-align:right;"><?= htmlspecialchars(number_format((float)$product['quantity_on_hand'], 2)) ?></td>
                                        <td style="text-align:right;"><?= htmlspecialchars(number_format((float)$product['min_quantity'], 2)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </aside>
            </section>

            <section class="panels">
                <article class="panel">
                    <header>
                        <h2>Upcoming deliveries</h2>
                        <span class="metric-sub">Next 6 scheduled</span>
                    </header>
                    <?php if (!$upcomingDeliveries): ?>
                        <div class="empty">No deliveries queued.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Driver</th>
                                    <th>Status</th>
                                    <th>Scheduled</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingDeliveries as $delivery): ?>
                                    <?php $meta = admin_dashboard_delivery_meta($delivery['status'] ?? null); ?>
                                    <tr>
                                        <td><?= htmlspecialchars($delivery['order_number'] ?: sprintf('Order #%04d', (int)$delivery['order_id'])) ?></td>
                                        <td><?= htmlspecialchars($delivery['customer_name'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($delivery['driver_name'] ?? 'Unassigned') ?></td>
                                        <td><span class="badge <?= htmlspecialchars($meta[1]) ?>"><?= htmlspecialchars($meta[0]) ?></span></td>
                                        <td><?= htmlspecialchars($delivery['scheduled_at'] ? date('M j, H:i', strtotime($delivery['scheduled_at'])) : '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </article>

                <article class="panel">
                    <header>
                        <h2>Recent payments</h2>
                        <span class="metric-sub">Latest receipts</span>
                    </header>
                    <?php if (!$recentPayments): ?>
                        <div class="empty">No payments recorded yet.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPayments as $payment): ?>
                                    <?php
                                    $amountParts = [];
                                    if ((float)$payment['amount_usd'] > 0) {
                                        $amountParts[] = 'USD ' . number_format((float)$payment['amount_usd'], 2);
                                    }
                                    if ((float)$payment['amount_lbp'] > 0) {
                                        $amountParts[] = 'LBP ' . number_format((float)$payment['amount_lbp'], 0);
                                    }
                                    $amountText = $amountParts ? implode(' · ', $amountParts) : '—';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($payment['invoice_number'] ?: ('#' . (int)$payment['invoice_id'])) ?></td>
                                        <td><?= htmlspecialchars($amountText) ?></td>
                                        <td><?= htmlspecialchars(str_replace('_', ' ', strtoupper($payment['method'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars($payment['received_at'] ? date('M j, H:i', strtotime($payment['received_at'])) : '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </article>
            </section>
        </div>
    </div>
</body>
</html>
