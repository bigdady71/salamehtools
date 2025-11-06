<?php
/**
 * Analytics Dashboard Query Test
 * Tests all queries used in analytics.php to ensure they work correctly
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== ANALYTICS DASHBOARD QUERY TEST ===\n\n";

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Test period calculation
    $period = '30days';
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d');

    echo "Period: $period\n";
    echo "Date Range: $dateFrom to $dateTo\n\n";

    // Test 1: Revenue Daily Trend
    echo "1. Testing Revenue Daily Trend Query...\n";
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
    $revenueDaily->execute(['from' => $dateFrom, 'to' => $dateTo]);
    $revenueDailyData = $revenueDaily->fetchAll(PDO::FETCH_ASSOC);
    echo "   Result: " . count($revenueDailyData) . " days with revenue\n";
    if (count($revenueDailyData) > 0) {
        $sample = $revenueDailyData[0];
        echo "   Sample: {$sample['date']} - \${$sample['revenue_usd']} USD - {$sample['invoice_count']} invoices\n";
    }
    echo "   ✅ PASSED\n\n";

    // Test 2: Top Customers
    echo "2. Testing Top Customers Query...\n";
    $topCustomers = $pdo->prepare("
        SELECT
            c.id, c.name,
            COUNT(DISTINCT o.id) as order_count,
            SUM(i.total_usd) as total_revenue_usd,
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
    $topCustomers->execute(['from' => $dateFrom, 'to' => $dateTo]);
    $topCustomersData = $topCustomers->fetchAll(PDO::FETCH_ASSOC);
    echo "   Result: " . count($topCustomersData) . " customers found\n";
    if (count($topCustomersData) > 0) {
        $sample = $topCustomersData[0];
        echo "   Top Customer: {$sample['name']} - \${$sample['total_revenue_usd']} USD - {$sample['order_count']} orders\n";
    }
    echo "   ✅ PASSED\n\n";

    // Test 3: Sales Rep Performance
    echo "3. Testing Sales Rep Performance Query...\n";
    $salesRepPerf = $pdo->prepare("
        SELECT
            u.id, u.name,
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
    $salesRepPerf->execute(['from' => $dateFrom, 'to' => $dateTo]);
    $salesRepData = $salesRepPerf->fetchAll(PDO::FETCH_ASSOC);
    echo "   Result: " . count($salesRepData) . " sales reps found\n";
    if (count($salesRepData) > 0) {
        $sample = $salesRepData[0];
        echo "   Top Rep: {$sample['name']} - \${$sample['total_sales_usd']} USD - {$sample['order_count']} orders\n";
    }
    echo "   ✅ PASSED\n\n";

    // Test 4: Top Products
    echo "4. Testing Top Products Query...\n";
    $topProducts = $pdo->prepare("
        SELECT
            p.id, p.sku, p.item_name,
            SUM(oi.quantity) as units_sold,
            SUM(oi.price_usd * oi.quantity) as total_revenue_usd,
            COUNT(DISTINCT o.id) as order_count
        FROM products p
        INNER JOIN order_items oi ON oi.product_id = p.id
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN invoices i ON i.order_id = o.id
        WHERE i.created_at >= :from AND i.created_at <= :to
        AND i.status IN ('issued', 'paid')
        GROUP BY p.id
        ORDER BY total_revenue_usd DESC
        LIMIT 10
    ");
    $topProducts->execute(['from' => $dateFrom, 'to' => $dateTo]);
    $topProductsData = $topProducts->fetchAll(PDO::FETCH_ASSOC);
    echo "   Result: " . count($topProductsData) . " products found\n";
    if (count($topProductsData) > 0) {
        $sample = $topProductsData[0];
        echo "   Top Product: {$sample['item_name']} ({$sample['sku']}) - \${$sample['total_revenue_usd']} USD - {$sample['units_sold']} units\n";
    }
    echo "   ✅ PASSED\n\n";

    // Test 5: Payment Methods
    echo "5. Testing Payment Methods Query...\n";
    $paymentMethods = $pdo->prepare("
        SELECT
            p.payment_method,
            COUNT(*) as transaction_count,
            SUM(p.amount_usd) as total_usd,
            SUM(p.amount_lbp) as total_lbp
        FROM payments p
        INNER JOIN invoices i ON i.id = p.invoice_id
        WHERE p.received_at >= :from AND p.received_at <= :to
        GROUP BY p.payment_method
        ORDER BY total_usd DESC
    ");
    $paymentMethods->execute(['from' => $dateFrom, 'to' => $dateTo]);
    $paymentMethodsData = $paymentMethods->fetchAll(PDO::FETCH_ASSOC);
    echo "   Result: " . count($paymentMethodsData) . " payment methods found\n";
    foreach ($paymentMethodsData as $pm) {
        echo "   - {$pm['payment_method']}: \${$pm['total_usd']} USD ({$pm['transaction_count']} transactions)\n";
    }
    echo "   ✅ PASSED\n\n";

    // Test 6: Financial Metrics - DSO
    echo "6. Testing DSO (Days Sales Outstanding) Query...\n";
    $dsoQuery = $pdo->query("
        SELECT
            AVG(DATEDIFF(COALESCE(p.received_at, CURDATE()), i.created_at)) as avg_days
        FROM invoices i
        LEFT JOIN payments p ON p.invoice_id = i.id
        WHERE i.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        AND i.status IN ('issued', 'paid')
    ");
    $dso = $dsoQuery->fetch(PDO::FETCH_ASSOC);
    $dsoValue = round($dso['avg_days'] ?? 0, 1);
    echo "   Result: DSO = $dsoValue days\n";
    echo "   ✅ PASSED\n\n";

    // Test 7: Outstanding AR
    echo "7. Testing Outstanding AR Query...\n";
    $outstandingAR = $pdo->query("
        SELECT
            SUM(i.total_usd - COALESCE(paid.total_paid_usd, 0)) as outstanding_usd,
            COUNT(*) as unpaid_invoice_count
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as total_paid_usd
            FROM payments
            GROUP BY invoice_id
        ) paid ON paid.invoice_id = i.id
        WHERE i.status IN ('issued', 'paid')
        AND (i.total_usd - COALESCE(paid.total_paid_usd, 0)) > 0
    ");
    $ar = $outstandingAR->fetch(PDO::FETCH_ASSOC);
    echo "   Result: \${$ar['outstanding_usd']} USD outstanding - {$ar['unpaid_invoice_count']} invoices\n";
    echo "   ✅ PASSED\n\n";

    // Test 8: Inventory Metrics
    echo "8. Testing Inventory Metrics Query...\n";
    $inventoryMetrics = $pdo->query("
        SELECT
            SUM(quantity_available * price_per_unit_usd) as inventory_value_usd,
            COUNT(CASE WHEN quantity_available < low_stock_threshold THEN 1 END) as low_stock_count
        FROM products
        WHERE is_active = 1
    ");
    $inventory = $inventoryMetrics->fetch(PDO::FETCH_ASSOC);
    echo "   Result: \${$inventory['inventory_value_usd']} USD inventory - {$inventory['low_stock_count']} low stock items\n";
    echo "   ✅ PASSED\n\n";

    // Test 9: Order Status Distribution
    echo "9. Testing Order Status Distribution Query...\n";
    $orderStatus = $pdo->prepare("
        SELECT
            ose.status,
            COUNT(DISTINCT ose.order_id) as order_count
        FROM order_status_events ose
        INNER JOIN (
            SELECT order_id, MAX(created_at) as latest_date
            FROM order_status_events
            GROUP BY order_id
        ) latest ON latest.order_id = ose.order_id AND latest.latest_date = ose.created_at
        INNER JOIN orders o ON o.id = ose.order_id
        WHERE o.created_at >= :from AND o.created_at <= :to
        GROUP BY ose.status
        ORDER BY order_count DESC
    ");
    $orderStatus->execute(['from' => $dateFrom, 'to' => $dateTo]);
    $orderStatusData = $orderStatus->fetchAll(PDO::FETCH_ASSOC);
    echo "   Result: " . count($orderStatusData) . " statuses found\n";
    foreach ($orderStatusData as $status) {
        echo "   - {$status['status']}: {$status['order_count']} orders\n";
    }
    echo "   ✅ PASSED\n\n";

    echo "=== ALL TESTS PASSED ✅ ===\n";
    echo "\nThe analytics dashboard queries are working correctly!\n";
    echo "You can now access the dashboard at: http://localhost/pages/admin/analytics.php\n";

} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Query failed at line: " . $e->getLine() . "\n";
    exit(1);
}
