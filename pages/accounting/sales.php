<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Filters
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$salesRepId = trim($_GET['sales_rep'] ?? '');
$customerId = trim($_GET['customer'] ?? '');
$view = trim($_GET['view'] ?? 'transactions');

// Get sales reps for filter
$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' AND is_active = 1 ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter
$customersStmt = $pdo->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name LIMIT 500");
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Build base conditions
$conditions = ["i.status IN ('issued', 'paid')"];
$params = [];

if ($dateFrom !== '') {
    $conditions[] = "i.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $conditions[] = "i.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

if ($salesRepId !== '') {
    $conditions[] = "o.sales_rep_id = :sales_rep_id";
    $params[':sales_rep_id'] = $salesRepId;
}

if ($customerId !== '') {
    $conditions[] = "o.customer_id = :customer_id";
    $params[':customer_id'] = $customerId;
}

$whereClause = implode(' AND ', $conditions);

// Get totals for the period
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT i.id) as total_invoices,
        COALESCE(SUM(i.total_usd), 0) as total_revenue_usd,
        COALESCE(SUM(i.total_lbp), 0) as total_revenue_lbp
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    WHERE $whereClause
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportData = [];
    $headers = [];

    if ($view === 'transactions') {
        $headers = ['Invoice #', 'Date', 'Customer', 'Sales Rep', 'Total USD', 'Total LBP', 'Status'];
        $exportStmt = $pdo->prepare("
            SELECT
                i.invoice_number,
                DATE(i.created_at) as invoice_date,
                c.name as customer_name,
                COALESCE(u.name, 'N/A') as sales_rep_name,
                i.total_usd,
                i.total_lbp,
                i.status
            FROM invoices i
            JOIN orders o ON o.id = i.order_id
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN users u ON u.id = o.sales_rep_id
            WHERE $whereClause
            ORDER BY i.created_at DESC
        ");
        $exportStmt->execute($params);
        $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'by_product') {
        $headers = ['SKU', 'Product', 'Qty Sold', 'Revenue USD', 'Revenue LBP'];
        $exportStmt = $pdo->prepare("
            SELECT
                p.sku,
                p.item_name,
                SUM(oi.quantity) as qty_sold,
                SUM(oi.quantity * oi.unit_price_usd) as revenue_usd,
                SUM(oi.quantity * oi.unit_price_lbp) as revenue_lbp
            FROM invoices i
            JOIN orders o ON o.id = i.order_id
            JOIN order_items oi ON oi.order_id = o.id
            JOIN products p ON p.id = oi.product_id
            WHERE $whereClause
            GROUP BY p.id, p.sku, p.item_name
            ORDER BY revenue_usd DESC
        ");
        $exportStmt->execute($params);
        $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'by_customer') {
        $headers = ['Customer', 'Invoice Count', 'Revenue USD', 'Revenue LBP'];
        $exportStmt = $pdo->prepare("
            SELECT
                c.name as customer_name,
                COUNT(DISTINCT i.id) as invoice_count,
                SUM(i.total_usd) as revenue_usd,
                SUM(i.total_lbp) as revenue_lbp
            FROM invoices i
            JOIN orders o ON o.id = i.order_id
            JOIN customers c ON c.id = o.customer_id
            WHERE $whereClause
            GROUP BY c.id, c.name
            ORDER BY revenue_usd DESC
        ");
        $exportStmt->execute($params);
        $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'by_rep') {
        $headers = ['Sales Rep', 'Invoice Count', 'Revenue USD', 'Revenue LBP'];
        $exportStmt = $pdo->prepare("
            SELECT
                COALESCE(u.name, 'No Rep Assigned') as sales_rep_name,
                COUNT(DISTINCT i.id) as invoice_count,
                SUM(i.total_usd) as revenue_usd,
                SUM(i.total_lbp) as revenue_lbp
            FROM invoices i
            JOIN orders o ON o.id = i.order_id
            LEFT JOIN users u ON u.id = o.sales_rep_id
            WHERE $whereClause
            GROUP BY o.sales_rep_id, u.name
            ORDER BY revenue_usd DESC
        ");
        $exportStmt->execute($params);
        $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_' . $view . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);

    foreach ($exportData as $row) {
        fputcsv($output, array_values($row));
    }

    fclose($output);
    exit;
}

// Fetch data based on view
$data = [];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if ($view === 'transactions') {
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.created_at,
            i.total_usd,
            i.total_lbp,
            i.status,
            c.name as customer_name,
            COALESCE(u.name, 'N/A') as sales_rep_name
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users u ON u.id = o.sales_rep_id
        WHERE $whereClause
        ORDER BY i.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($view === 'by_product') {
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.sku,
            p.item_name,
            SUM(oi.quantity) as qty_sold,
            SUM(oi.quantity * oi.unit_price_usd) as revenue_usd,
            SUM(oi.quantity * oi.unit_price_lbp) as revenue_lbp
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        JOIN order_items oi ON oi.order_id = o.id
        JOIN products p ON p.id = oi.product_id
        WHERE $whereClause
        GROUP BY p.id, p.sku, p.item_name
        ORDER BY revenue_usd DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($view === 'by_customer') {
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.name,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(i.total_usd) as revenue_usd,
            SUM(i.total_lbp) as revenue_lbp
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        JOIN customers c ON c.id = o.customer_id
        WHERE $whereClause
        GROUP BY c.id, c.name
        ORDER BY revenue_usd DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($view === 'by_rep') {
    $stmt = $pdo->prepare("
        SELECT
            o.sales_rep_id,
            COALESCE(u.name, 'No Rep Assigned') as name,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(i.total_usd) as revenue_usd,
            SUM(i.total_lbp) as revenue_lbp
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        LEFT JOIN users u ON u.id = o.sales_rep_id
        WHERE $whereClause
        GROUP BY o.sales_rep_id, u.name
        ORDER BY revenue_usd DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

accounting_render_layout_start([
    'title' => 'Sales',
    'heading' => 'Sales Tracking',
    'subtitle' => 'Revenue analysis and sales reports',
    'active' => 'sales',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Total Revenue (USD)</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_revenue_usd']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Total Revenue (LBP)</div>
        <div class="value"><?= format_currency_lbp((float)$totals['total_revenue_lbp']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Invoices</div>
        <div class="value"><?= number_format((int)$totals['total_invoices']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Avg. Invoice Value</div>
        <div class="value"><?= $totals['total_invoices'] > 0 ? format_currency_usd((float)$totals['total_revenue_usd'] / (int)$totals['total_invoices']) : '$0.00' ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="filter-input">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="filter-input">

        <select name="sales_rep" class="filter-input">
            <option value="">All Sales Reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="customer" class="filter-input">
            <option value="">All Customers</option>
            <?php foreach ($customers as $cust): ?>
                <option value="<?= (int)$cust['id'] ?>" <?= $customerId == $cust['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cust['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="sales.php" class="btn">Clear</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="margin-left: auto;">Export CSV</a>
    </form>

    <div class="tabs">
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'transactions', 'page' => 1])) ?>" class="tab <?= $view === 'transactions' ? 'active' : '' ?>">By Transaction</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'by_product', 'page' => 1])) ?>" class="tab <?= $view === 'by_product' ? 'active' : '' ?>">By Product</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'by_customer', 'page' => 1])) ?>" class="tab <?= $view === 'by_customer' ? 'active' : '' ?>">By Customer</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'by_rep', 'page' => 1])) ?>" class="tab <?= $view === 'by_rep' ? 'active' : '' ?>">By Sales Rep</a>
    </div>

    <?php if ($view === 'transactions'): ?>
        <table>
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Sales Rep</th>
                    <th class="text-right">Amount (USD)</th>
                    <th class="text-right">Amount (LBP)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No transactions found</td></tr>
                <?php else: ?>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($row['invoice_number'] ?? '-') ?></code></td>
                            <td><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td><?= htmlspecialchars($row['sales_rep_name']) ?></td>
                            <td class="text-right"><?= format_currency_usd((float)$row['total_usd']) ?></td>
                            <td class="text-right"><?= format_currency_lbp((float)$row['total_lbp']) ?></td>
                            <td>
                                <span class="badge badge-<?= $row['status'] === 'paid' ? 'success' : 'info' ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($view === 'by_product'): ?>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th class="text-right">Qty Sold</th>
                    <th class="text-right">Revenue (USD)</th>
                    <th class="text-right">Revenue (LBP)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No product sales found</td></tr>
                <?php else: ?>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                            <td><?= htmlspecialchars(substr($row['item_name'], 0, 50)) ?></td>
                            <td class="text-right"><?= number_format((int)$row['qty_sold']) ?></td>
                            <td class="text-right"><?= format_currency_usd((float)$row['revenue_usd']) ?></td>
                            <td class="text-right"><?= format_currency_lbp((float)$row['revenue_lbp']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($view === 'by_customer'): ?>
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th class="text-right">Invoices</th>
                    <th class="text-right">Revenue (USD)</th>
                    <th class="text-right">Revenue (LBP)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                    <tr><td colspan="4" class="text-center text-muted">No customer sales found</td></tr>
                <?php else: ?>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td class="text-right"><?= number_format((int)$row['invoice_count']) ?></td>
                            <td class="text-right"><?= format_currency_usd((float)$row['revenue_usd']) ?></td>
                            <td class="text-right"><?= format_currency_lbp((float)$row['revenue_lbp']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($view === 'by_rep'): ?>
        <table>
            <thead>
                <tr>
                    <th>Sales Rep</th>
                    <th class="text-right">Invoices</th>
                    <th class="text-right">Revenue (USD)</th>
                    <th class="text-right">Revenue (LBP)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                    <tr><td colspan="4" class="text-center text-muted">No sales rep data found</td></tr>
                <?php else: ?>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td class="text-right"><?= number_format((int)$row['invoice_count']) ?></td>
                            <td class="text-right"><?= format_currency_usd((float)$row['revenue_usd']) ?></td>
                            <td class="text-right"><?= format_currency_lbp((float)$row['revenue_lbp']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
accounting_render_layout_end();
