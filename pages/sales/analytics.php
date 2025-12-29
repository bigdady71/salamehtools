<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Performance Analytics';
$salesRepId = (int)$user['id'];
$salesRepName = $user['name'] ?? 'Sales Rep';

// Period filter
$period = $_GET['period'] ?? '30days';
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;

// Calculate date ranges
switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d 23:59:59');
        $periodLabel = 'Today';
        break;
    case '7days':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d 23:59:59');
        $periodLabel = 'Last 7 Days';
        break;
    case '30days':
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d 23:59:59');
        $periodLabel = 'Last 30 Days';
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t 23:59:59');
        $periodLabel = 'This Month';
        break;
    case 'quarter':
        $currentQuarter = ceil(date('n') / 3);
        $dateFrom = date('Y-m-d', strtotime(date('Y') . '-' . (($currentQuarter - 1) * 3 + 1) . '-01'));
        $dateTo = date('Y-m-t 23:59:59', strtotime($dateFrom . ' +2 months'));
        $periodLabel = 'This Quarter (Q' . $currentQuarter . ')';
        break;
    case 'year':
        $dateFrom = date('Y-01-01');
        $dateTo = date('Y-12-31 23:59:59');
        $periodLabel = 'This Year';
        break;
    case 'custom':
        if ($customStart && $customEnd) {
            $dateFrom = $customStart;
            $dateTo = $customEnd . ' 23:59:59';
            $periodLabel = date('M d, Y', strtotime($customStart)) . ' - ' . date('M d, Y', strtotime($customEnd));
        } else {
            $dateFrom = date('Y-m-d', strtotime('-30 days'));
            $dateTo = date('Y-m-d 23:59:59');
            $periodLabel = 'Last 30 Days';
        }
        break;
    default:
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d 23:59:59');
        $periodLabel = 'Last 30 Days';
}

// Calculate comparison period (same length, previous period)
$daysInPeriod = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
$comparisonFrom = date('Y-m-d', strtotime($dateFrom . " -{$daysInPeriod} days"));
$comparisonTo = date('Y-m-d 23:59:59', strtotime($dateFrom . ' -1 day'));

try {
    // ============================================================
    // 1. QUOTA TRACKING
    // ============================================================
    $currentMonth = (int)date('n');
    $currentYear = (int)date('Y');

    $quotaStmt = $pdo->prepare("
        SELECT
            COALESCE(sq.quota_usd, 0) as quota_usd,
            COALESCE(SUM(i.total_usd), 0) as actual_usd,
            CASE
                WHEN COALESCE(sq.quota_usd, 0) > 0
                THEN (COALESCE(SUM(i.total_usd), 0) / sq.quota_usd) * 100
                ELSE 0
            END as achievement_percent
        FROM users u
        LEFT JOIN sales_quotas sq ON sq.sales_rep_id = u.id
            AND sq.year = :year
            AND sq.month = :month
        LEFT JOIN orders o ON o.sales_rep_id = u.id
            AND YEAR(o.created_at) = :year
            AND MONTH(o.created_at) = :month
        LEFT JOIN invoices i ON i.order_id = o.id
            AND i.status IN ('issued', 'paid')
        WHERE u.id = :rep_id
        GROUP BY u.id, sq.quota_usd
    ");
    $quotaStmt->execute([
        'rep_id' => $salesRepId,
        'year' => $currentYear,
        'month' => $currentMonth
    ]);
    $quotaData = $quotaStmt->fetch(PDO::FETCH_ASSOC);

    // Cast to proper types
    $quotaData['quota_usd'] = (float)$quotaData['quota_usd'];
    $quotaData['actual_usd'] = (float)$quotaData['actual_usd'];
    $quotaData['achievement_percent'] = (float)$quotaData['achievement_percent'];

    // ============================================================
    // 2. REVENUE METRICS (Current & Comparison Period)
    // ============================================================
    $revenueStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(i.total_usd), 0) as total_revenue_usd,
            COALESCE(AVG(i.total_usd), 0) as avg_order_value_usd,
            COUNT(DISTINCT o.customer_id) as unique_customers
        FROM orders o
        INNER JOIN invoices i ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND i.created_at >= :from
        AND i.created_at <= :to
        AND i.status IN ('issued', 'paid')
    ");

    // Current period
    $revenueStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $dateFrom,
        'to' => $dateTo
    ]);
    $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);

    // Cast to proper types
    $revenue['order_count'] = (int)$revenue['order_count'];
    $revenue['total_revenue_usd'] = (float)$revenue['total_revenue_usd'];
    $revenue['avg_order_value_usd'] = (float)$revenue['avg_order_value_usd'];
    $revenue['unique_customers'] = (int)$revenue['unique_customers'];

    // Comparison period
    $revenueStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $comparisonFrom,
        'to' => $comparisonTo
    ]);
    $revenueComparison = $revenueStmt->fetch(PDO::FETCH_ASSOC);

    // Cast to proper types
    $revenueComparison['order_count'] = (int)$revenueComparison['order_count'];
    $revenueComparison['total_revenue_usd'] = (float)$revenueComparison['total_revenue_usd'];
    $revenueComparison['avg_order_value_usd'] = (float)$revenueComparison['avg_order_value_usd'];
    $revenueComparison['unique_customers'] = (int)$revenueComparison['unique_customers'];

    // Calculate growth
    $revenueGrowth = 0;
    if ($revenueComparison['total_revenue_usd'] > 0) {
        $revenueGrowth = (($revenue['total_revenue_usd'] - $revenueComparison['total_revenue_usd']) / $revenueComparison['total_revenue_usd']) * 100;
    }

    $ordersGrowth = 0;
    if ($revenueComparison['order_count'] > 0) {
        $ordersGrowth = (($revenue['order_count'] - $revenueComparison['order_count']) / $revenueComparison['order_count']) * 100;
    }

    $customersGrowth = 0;
    if ($revenueComparison['unique_customers'] > 0) {
        $customersGrowth = (($revenue['unique_customers'] - $revenueComparison['unique_customers']) / $revenueComparison['unique_customers']) * 100;
    }

    // ============================================================
    // 3. SALES FUNNEL METRICS
    // ============================================================
    $funnelStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(DISTINCT CASE WHEN i.id IS NOT NULL THEN o.id END) as invoiced_orders,
            COUNT(DISTINCT CASE WHEN i.status = 'paid' THEN o.id END) as paid_orders,
            COALESCE(SUM(o.total_usd), 0) as orders_value,
            COALESCE(SUM(CASE WHEN i.id IS NOT NULL THEN i.total_usd END), 0) as invoiced_value,
            COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_usd END), 0) as paid_value
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND o.created_at >= :from
        AND o.created_at <= :to
    ");
    $funnelStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $dateFrom,
        'to' => $dateTo
    ]);
    $funnel = $funnelStmt->fetch(PDO::FETCH_ASSOC);

    // Cast to proper types
    $funnel['total_orders'] = (int)$funnel['total_orders'];
    $funnel['invoiced_orders'] = (int)$funnel['invoiced_orders'];
    $funnel['paid_orders'] = (int)$funnel['paid_orders'];
    $funnel['orders_value'] = (float)$funnel['orders_value'];
    $funnel['invoiced_value'] = (float)$funnel['invoiced_value'];
    $funnel['paid_value'] = (float)$funnel['paid_value'];

    // Calculate funnel conversion rates
    $invoicedRate = $funnel['total_orders'] > 0 ? ($funnel['invoiced_orders'] / $funnel['total_orders']) * 100 : 0;
    $paidRate = $funnel['invoiced_orders'] > 0 ? ($funnel['paid_orders'] / $funnel['invoiced_orders']) * 100 : 0;
    $overallConversion = $funnel['total_orders'] > 0 ? ($funnel['paid_orders'] / $funnel['total_orders']) * 100 : 0;

    // ============================================================
    // 4. ORDER TYPE BREAKDOWN
    // ============================================================
    $orderTypeStmt = $pdo->prepare("
        SELECT
            o.order_type,
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(i.total_usd), 0) as revenue_usd
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id AND i.status IN ('issued', 'paid')
        WHERE o.sales_rep_id = :rep_id
        AND o.created_at >= :from
        AND o.created_at <= :to
        GROUP BY o.order_type
        ORDER BY revenue_usd DESC
    ");
    $orderTypeStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $dateFrom,
        'to' => $dateTo
    ]);
    $orderTypes = $orderTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast to proper types
    foreach ($orderTypes as &$type) {
        $type['order_count'] = (int)$type['order_count'];
        $type['revenue_usd'] = (float)$type['revenue_usd'];
    }
    unset($type);

    // ============================================================
    // 5. PRODUCT PERFORMANCE BY CATEGORY
    // ============================================================
    $categoryPerformanceStmt = $pdo->prepare("
        SELECT
            COALESCE(p.topcat, 'Uncategorized') as category,
            COUNT(DISTINCT p.id) as product_count,
            SUM(oi.quantity) as units_sold,
            COALESCE(SUM(oi.quantity * oi.unit_price_usd), 0) as revenue_usd
        FROM order_items oi
        INNER JOIN products p ON oi.product_id = p.id
        INNER JOIN orders o ON oi.order_id = o.id
        INNER JOIN invoices i ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND i.created_at >= :from
        AND i.created_at <= :to
        AND i.status IN ('issued', 'paid')
        GROUP BY p.topcat
        ORDER BY revenue_usd DESC
        LIMIT 10
    ");
    $categoryPerformanceStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $dateFrom,
        'to' => $dateTo
    ]);
    $categoryPerformance = $categoryPerformanceStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast to proper types
    foreach ($categoryPerformance as &$cat) {
        $cat['product_count'] = (int)$cat['product_count'];
        $cat['units_sold'] = (float)$cat['units_sold'];
        $cat['revenue_usd'] = (float)$cat['revenue_usd'];
    }
    unset($cat);

    // ============================================================
    // 6. TOP PRODUCTS
    // ============================================================
    $topProductsStmt = $pdo->prepare("
        SELECT
            p.sku,
            p.item_name,
            SUM(oi.quantity) as units_sold,
            COALESCE(SUM(oi.quantity * oi.unit_price_usd), 0) as revenue_usd,
            COUNT(DISTINCT oi.order_id) as order_count
        FROM order_items oi
        INNER JOIN products p ON oi.product_id = p.id
        INNER JOIN orders o ON oi.order_id = o.id
        INNER JOIN invoices i ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND i.created_at >= :from
        AND i.created_at <= :to
        AND i.status IN ('issued', 'paid')
        GROUP BY p.id, p.sku, p.item_name
        ORDER BY revenue_usd DESC
        LIMIT 10
    ");
    $topProductsStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $dateFrom,
        'to' => $dateTo
    ]);
    $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast to proper types
    foreach ($topProducts as &$prod) {
        $prod['units_sold'] = (float)$prod['units_sold'];
        $prod['revenue_usd'] = (float)$prod['revenue_usd'];
        $prod['order_count'] = (int)$prod['order_count'];
    }
    unset($prod);

    // ============================================================
    // 7. CUSTOMER ENGAGEMENT METRICS
    // ============================================================
    $customerEngagementStmt = $pdo->prepare("
        SELECT
            c.id,
            c.name,
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(i.total_usd), 0) as lifetime_value_usd,
            MAX(o.created_at) as last_order_date,
            DATEDIFF(CURDATE(), MAX(o.created_at)) as days_since_last_order,
            CASE
                WHEN COUNT(DISTINCT o.id) > 1
                THEN DATEDIFF(MAX(o.created_at), MIN(o.created_at)) / (COUNT(DISTINCT o.id) - 1)
                ELSE NULL
            END as avg_days_between_orders
        FROM customers c
        INNER JOIN orders o ON o.customer_id = c.id
        LEFT JOIN invoices i ON i.order_id = o.id AND i.status IN ('issued', 'paid')
        WHERE c.assigned_sales_rep_id = :rep_id
        AND o.created_at >= :from
        AND o.created_at <= :to
        GROUP BY c.id, c.name
        ORDER BY lifetime_value_usd DESC
        LIMIT 10
    ");
    $customerEngagementStmt->execute([
        'rep_id' => $salesRepId,
        'from' => $dateFrom,
        'to' => $dateTo
    ]);
    $topCustomers = $customerEngagementStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast to proper types
    foreach ($topCustomers as &$cust) {
        $cust['order_count'] = (int)$cust['order_count'];
        $cust['lifetime_value_usd'] = (float)$cust['lifetime_value_usd'];
        $cust['days_since_last_order'] = (int)$cust['days_since_last_order'];
        $cust['avg_days_between_orders'] = $cust['avg_days_between_orders'] !== null ? (float)$cust['avg_days_between_orders'] : null;
    }
    unset($cust);

    // ============================================================
    // 8. RECEIVABLES AGING
    // ============================================================
    $receivablesStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.issued_at) <= 30 THEN i.total_usd ELSE 0 END) as current_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.issued_at) BETWEEN 31 AND 60 THEN i.total_usd ELSE 0 END) as days_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.issued_at) BETWEEN 61 AND 90 THEN i.total_usd ELSE 0 END) as days_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.issued_at) > 90 THEN i.total_usd ELSE 0 END) as over_90,
            COALESCE(SUM(i.total_usd), 0) as total_outstanding
        FROM invoices i
        INNER JOIN orders o ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND i.status = 'issued'
    ");
    $receivablesStmt->execute(['rep_id' => $salesRepId]);
    $receivables = $receivablesStmt->fetch(PDO::FETCH_ASSOC);

    // Cast to proper types
    $receivables['current_30'] = (float)($receivables['current_30'] ?? 0);
    $receivables['days_31_60'] = (float)($receivables['days_31_60'] ?? 0);
    $receivables['days_61_90'] = (float)($receivables['days_61_90'] ?? 0);
    $receivables['over_90'] = (float)($receivables['over_90'] ?? 0);
    $receivables['total_outstanding'] = (float)$receivables['total_outstanding'];

    // ============================================================
    // 9. TIME-BASED TRENDS (Last 30 days for charts)
    // ============================================================
    $trendsStmt = $pdo->prepare("
        SELECT
            DATE(i.created_at) as date,
            COUNT(DISTINCT o.id) as orders,
            COALESCE(SUM(i.total_usd), 0) as revenue_usd
        FROM orders o
        INNER JOIN invoices i ON i.order_id = o.id
        WHERE o.sales_rep_id = :rep_id
        AND i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND i.status IN ('issued', 'paid')
        GROUP BY DATE(i.created_at)
        ORDER BY date ASC
    ");
    $trendsStmt->execute(['rep_id' => $salesRepId]);
    $dailyTrends = $trendsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast to proper types
    foreach ($dailyTrends as &$trend) {
        $trend['orders'] = (int)$trend['orders'];
        $trend['revenue_usd'] = (float)$trend['revenue_usd'];
    }
    unset($trend);

    // ============================================================
    // 10. PERFORMANCE ALERTS
    // ============================================================
    $alerts = [];

    // Alert: Quota achievement
    if ($quotaData['quota_usd'] > 0) {
        if ($quotaData['achievement_percent'] >= 100) {
            $alerts[] = [
                'type' => 'success',
                'title' => 'Quota Achieved!',
                'message' => sprintf('Congratulations! You\'ve achieved %.1f%% of your monthly quota.', $quotaData['achievement_percent'])
            ];
        } elseif ($quotaData['achievement_percent'] < 50 && date('d') > 15) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Quota Progress',
                'message' => sprintf('You\'re at %.1f%% of your monthly quota with %d days remaining.', $quotaData['achievement_percent'], (int)(date('t') - date('d')))
            ];
        }
    }

    // Alert: Revenue drop
    if ($revenueGrowth < -20) {
        $alerts[] = [
            'type' => 'danger',
            'title' => 'Revenue Decline',
            'message' => sprintf('Revenue decreased by %.1f%% compared to the previous period.', abs($revenueGrowth))
        ];
    }

    // Alert: Overdue receivables
    if ($receivables['over_90'] > 0) {
        $alerts[] = [
            'type' => 'warning',
            'title' => 'Overdue Receivables',
            'message' => sprintf('You have $%.2f in receivables over 90 days old.', $receivables['over_90'])
        ];
    }

} catch (Exception $e) {
    error_log("Sales Analytics Error: " . $e->getMessage());
    // Initialize empty data structures
    $quotaData = ['quota_usd' => 0, 'actual_usd' => 0, 'achievement_percent' => 0];
    $revenue = ['order_count' => 0, 'total_revenue_usd' => 0, 'avg_order_value_usd' => 0, 'unique_customers' => 0];
    $revenueGrowth = 0;
    $ordersGrowth = 0;
    $customersGrowth = 0;
    $funnel = ['total_orders' => 0, 'invoiced_orders' => 0, 'paid_orders' => 0, 'orders_value' => 0, 'invoiced_value' => 0, 'paid_value' => 0];
    $invoicedRate = 0;
    $paidRate = 0;
    $overallConversion = 0;
    $orderTypes = [];
    $categoryPerformance = [];
    $topProducts = [];
    $topCustomers = [];
    $receivables = ['current_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0, 'total_outstanding' => 0];
    $dailyTrends = [];
    $alerts = [[
        'type' => 'danger',
        'title' => 'Error Loading Analytics',
        'message' => 'There was an error loading your analytics data. Please try again later.'
    ]];
}

// Prepare chart data
$trendLabels = array_map(fn($row) => date('M d', strtotime($row['date'])), $dailyTrends);
$trendRevenue = array_map(fn($row) => (float)$row['revenue_usd'], $dailyTrends);
$trendOrders = array_map(fn($row) => (int)$row['orders'], $dailyTrends);

$orderTypeLabels = array_map(fn($row) => ucwords(str_replace('_', ' ', $row['order_type'] ?? 'Unknown')), $orderTypes);
$orderTypeValues = array_map(fn($row) => (float)$row['revenue_usd'], $orderTypes);

$categoryLabels = array_map(fn($row) => $row['category'], $categoryPerformance);
$categoryValues = array_map(fn($row) => (float)$row['revenue_usd'], $categoryPerformance);

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => '📊 Performance Analytics',
    'subtitle' => 'Your personal sales dashboard for ' . $periodLabel,
    'user' => $user,
    'active' => 'analytics',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>'
]);
?>

<style>
:root {
    --color-success: #10b981;
    --color-warning: #f59e0b;
    --color-danger: #ef4444;
    --color-info: #3b82f6;
    --color-purple: #8b5cf6;
}

.filters-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.filter-form {
    display: flex;
    gap: 16px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
}

.filter-group label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
}

.filter-group select,
.filter-group input {
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.875rem;
    transition: border-color 0.2s;
}

.filter-group select:focus,
.filter-group input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}

.alerts-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
}

.alert {
    padding: 16px 20px;
    border-radius: 10px;
    border-left: 4px solid;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.alert.success {
    border-left-color: var(--color-success);
    background: rgba(16, 185, 129, 0.05);
}

.alert.warning {
    border-left-color: var(--color-warning);
    background: rgba(245, 158, 11, 0.05);
}

.alert.danger {
    border-left-color: var(--color-danger);
    background: rgba(239, 68, 68, 0.05);
}

.alert-title {
    font-weight: 700;
    margin-bottom: 4px;
    font-size: 0.95rem;
}

.alert.success .alert-title { color: var(--color-success); }
.alert.warning .alert-title { color: var(--color-warning); }
.alert.danger .alert-title { color: var(--color-danger); }

.alert-message {
    font-size: 0.875rem;
    color: #6b7280;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.metric-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
    overflow: hidden;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--card-color, #e5e7eb);
}

.metric-card.revenue { --card-color: var(--color-success); }
.metric-card.orders { --card-color: var(--color-info); }
.metric-card.customers { --card-color: var(--color-purple); }
.metric-card.quota { --card-color: var(--color-warning); }

.metric-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
}

.metric-value {
    font-size: 2.25rem;
    font-weight: 800;
    color: #111827;
    margin-bottom: 8px;
    line-height: 1;
}

.metric-sub {
    font-size: 0.875rem;
    color: #9ca3af;
}

.metric-growth {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 20px;
    margin-top: 8px;
}

.metric-growth.positive {
    background: rgba(16, 185, 129, 0.1);
    color: var(--color-success);
}

.metric-growth.negative {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-danger);
}

.quota-progress {
    margin-top: 12px;
}

.progress-bar {
    width: 100%;
    height: 12px;
    background: #e5e7eb;
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--color-warning), var(--color-success));
    border-radius: 20px;
    transition: width 0.5s ease;
}

.progress-label {
    font-size: 0.75rem;
    color: #6b7280;
    text-align: right;
}

.funnel-section {
    background: white;
    border-radius: 12px;
    padding: 28px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 32px;
}

.funnel-section h2 {
    margin: 0 0 24px 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
}

.funnel-stages {
    display: flex;
    gap: 16px;
    align-items: center;
    justify-content: space-between;
}

.funnel-stage {
    flex: 1;
    text-align: center;
    padding: 20px;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border-radius: 10px;
    position: relative;
}

.funnel-stage::after {
    content: '→';
    position: absolute;
    right: -20px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1.5rem;
    color: #9ca3af;
}

.funnel-stage:last-child::after {
    display: none;
}

.funnel-stage.active {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.15) 0%, rgba(6, 182, 212, 0.15) 100%);
}

.funnel-stage-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: 8px;
}

.funnel-stage-value {
    font-size: 1.75rem;
    font-weight: 800;
    color: #111827;
    margin-bottom: 4px;
}

.funnel-stage-sub {
    font-size: 0.875rem;
    color: #6b7280;
}

.funnel-conversion {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-success);
    margin-top: 8px;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.chart-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.chart-card h3 {
    margin: 0 0 20px 0;
    font-size: 1.125rem;
    font-weight: 700;
    color: #111827;
}

.chart-container {
    position: relative;
    height: 300px;
}

.data-section {
    background: white;
    border-radius: 12px;
    padding: 28px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 32px;
}

.data-section h3 {
    margin: 0 0 20px 0;
    font-size: 1.125rem;
    font-weight: 700;
    color: #111827;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: #f9fafb;
    padding: 12px 16px;
    text-align: left;
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px 16px;
    font-size: 0.875rem;
    color: #111827;
    border-bottom: 1px solid #f3f4f6;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.text-right {
    text-align: right;
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--color-success);
}

.badge-warning {
    background: rgba(245, 158, 11, 0.1);
    color: var(--color-warning);
}

.badge-danger {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-danger);
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 16px;
}

.empty-state-text {
    font-size: 1rem;
    font-weight: 500;
}

@media (max-width: 768px) {
    .funnel-stages {
        flex-direction: column;
    }

    .funnel-stage::after {
        content: '↓';
        right: 50%;
        top: auto;
        bottom: -24px;
        transform: translateX(50%);
    }

    .charts-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Period Filter -->
<div class="filters-bar">
    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label for="period">Time Period</label>
            <select name="period" id="period" onchange="toggleCustomDates()">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="7days" <?= $period === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="30days" <?= $period === '30days' ? 'selected' : '' ?>>Last 30 Days</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
                <option value="quarter" <?= $period === 'quarter' ? 'selected' : '' ?>>This Quarter</option>
                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>This Year</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>

        <div class="filter-group" id="custom-dates" style="display: <?= $period === 'custom' ? 'flex' : 'none' ?>;">
            <label for="start_date">From</label>
            <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($customStart ?? '') ?>">
        </div>

        <div class="filter-group" id="custom-dates-to" style="display: <?= $period === 'custom' ? 'flex' : 'none' ?>;">
            <label for="end_date">To</label>
            <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($customEnd ?? '') ?>">
        </div>

        <div class="filter-group">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-info">Apply Filter</button>
        </div>
    </form>
</div>

<script>
function toggleCustomDates() {
    const period = document.getElementById('period').value;
    const customDates = document.getElementById('custom-dates');
    const customDatesTo = document.getElementById('custom-dates-to');
    const display = period === 'custom' ? 'flex' : 'none';
    customDates.style.display = display;
    customDatesTo.style.display = display;
}
</script>

<!-- Alerts -->
<?php if (!empty($alerts)): ?>
<div class="alerts-container">
    <?php foreach ($alerts as $alert): ?>
        <div class="alert <?= htmlspecialchars($alert['type']) ?>">
            <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
            <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Key Performance Metrics -->
<div class="metrics-grid">
    <div class="metric-card revenue">
        <div class="metric-label">💰 Total Revenue</div>
        <div class="metric-value">$<?= number_format($revenue['total_revenue_usd'], 2) ?></div>
        <div class="metric-sub"><?= number_format($revenue['order_count']) ?> orders</div>
        <?php if ($revenueGrowth != 0): ?>
            <div class="metric-growth <?= $revenueGrowth > 0 ? 'positive' : 'negative' ?>">
                <?= $revenueGrowth > 0 ? '↑' : '↓' ?> <?= number_format(abs($revenueGrowth), 1) ?>% vs previous period
            </div>
        <?php endif; ?>
    </div>

    <div class="metric-card orders">
        <div class="metric-label">📦 Orders Placed</div>
        <div class="metric-value"><?= number_format($revenue['order_count']) ?></div>
        <div class="metric-sub">Avg: $<?= number_format($revenue['avg_order_value_usd'], 2) ?></div>
        <?php if ($ordersGrowth != 0): ?>
            <div class="metric-growth <?= $ordersGrowth > 0 ? 'positive' : 'negative' ?>">
                <?= $ordersGrowth > 0 ? '↑' : '↓' ?> <?= number_format(abs($ordersGrowth), 1) ?>% vs previous period
            </div>
        <?php endif; ?>
    </div>

    <div class="metric-card customers">
        <div class="metric-label">👥 Active Customers</div>
        <div class="metric-value"><?= number_format($revenue['unique_customers']) ?></div>
        <div class="metric-sub">Unique customers</div>
        <?php if ($customersGrowth != 0): ?>
            <div class="metric-growth <?= $customersGrowth > 0 ? 'positive' : 'negative' ?>">
                <?= $customersGrowth > 0 ? '↑' : '↓' ?> <?= number_format(abs($customersGrowth), 1) ?>% vs previous period
            </div>
        <?php endif; ?>
    </div>

    <div class="metric-card quota">
        <div class="metric-label">🎯 Monthly Quota</div>
        <div class="metric-value"><?= number_format($quotaData['achievement_percent'], 1) ?>%</div>
        <div class="metric-sub">$<?= number_format($quotaData['actual_usd'], 2) ?> / $<?= number_format($quotaData['quota_usd'], 2) ?></div>
        <?php if ($quotaData['quota_usd'] > 0): ?>
            <div class="quota-progress">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= min(100, $quotaData['achievement_percent']) ?>%"></div>
                </div>
                <div class="progress-label">
                    <?php
                    $remaining = $quotaData['quota_usd'] - $quotaData['actual_usd'];
                    if ($remaining > 0) {
                        echo '$' . number_format($remaining, 2) . ' to goal';
                    } else {
                        echo 'Goal exceeded!';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sales Funnel -->
<div class="funnel-section">
    <h2>🎯 Sales Funnel Performance</h2>
    <div class="funnel-stages">
        <div class="funnel-stage active">
            <div class="funnel-stage-label">Orders Created</div>
            <div class="funnel-stage-value"><?= number_format($funnel['total_orders']) ?></div>
            <div class="funnel-stage-sub">$<?= number_format($funnel['orders_value'], 2) ?></div>
        </div>

        <div class="funnel-stage <?= $funnel['invoiced_orders'] > 0 ? 'active' : '' ?>">
            <div class="funnel-stage-label">Invoiced</div>
            <div class="funnel-stage-value"><?= number_format($funnel['invoiced_orders']) ?></div>
            <div class="funnel-stage-sub">$<?= number_format($funnel['invoiced_value'], 2) ?></div>
            <div class="funnel-conversion"><?= number_format($invoicedRate, 1) ?>% conversion</div>
        </div>

        <div class="funnel-stage <?= $funnel['paid_orders'] > 0 ? 'active' : '' ?>">
            <div class="funnel-stage-label">Paid</div>
            <div class="funnel-stage-value"><?= number_format($funnel['paid_orders']) ?></div>
            <div class="funnel-stage-sub">$<?= number_format($funnel['paid_value'], 2) ?></div>
            <div class="funnel-conversion"><?= number_format($paidRate, 1) ?>% collection</div>
        </div>
    </div>
    <div style="text-align: center; margin-top: 20px; font-size: 0.875rem; color: #6b7280;">
        <strong>Overall Conversion:</strong> <?= number_format($overallConversion, 1) ?>% (Orders → Paid)
    </div>
</div>

<!-- Charts -->
<div class="charts-grid">
    <div class="chart-card">
        <h3>📈 30-Day Revenue Trend</h3>
        <div class="chart-container">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>📊 Order Type Breakdown</h3>
        <div class="chart-container">
            <canvas id="orderTypeChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>🏷️ Top Product Categories</h3>
        <div class="chart-container">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>💵 Receivables Aging</h3>
        <div class="chart-container">
            <canvas id="receivablesChart"></canvas>
        </div>
    </div>
</div>

<!-- Top Products Table -->
<div class="data-section">
    <h3>🏆 Top 10 Products (<?= htmlspecialchars($periodLabel) ?>)</h3>
    <?php if (!empty($topProducts)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th class="text-right">Units Sold</th>
                    <th class="text-right">Revenue (USD)</th>
                    <th class="text-right">Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topProducts as $idx => $product): ?>
                    <tr>
                        <td><strong>#<?= $idx + 1 ?></strong></td>
                        <td><code style="background: #f3f4f6; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($product['sku']) ?></code></td>
                        <td><?= htmlspecialchars($product['item_name']) ?></td>
                        <td class="text-right"><?= number_format($product['units_sold']) ?></td>
                        <td class="text-right"><strong>$<?= number_format($product['revenue_usd'], 2) ?></strong></td>
                        <td class="text-right"><?= number_format($product['order_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">📦</div>
            <div class="empty-state-text">No product data for the selected period</div>
        </div>
    <?php endif; ?>
</div>

<!-- Top Customers Table -->
<div class="data-section">
    <h3>⭐ Top 10 Customers (<?= htmlspecialchars($periodLabel) ?>)</h3>
    <?php if (!empty($topCustomers)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Customer Name</th>
                    <th class="text-right">Orders</th>
                    <th class="text-right">Lifetime Value</th>
                    <th class="text-right">Last Order</th>
                    <th class="text-right">Engagement</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topCustomers as $idx => $customer): ?>
                    <?php
                    $daysSince = (int)$customer['days_since_last_order'];
                    $engagementBadge = 'badge-success';
                    $engagementText = 'Active';

                    if ($daysSince > 60) {
                        $engagementBadge = 'badge-danger';
                        $engagementText = 'At Risk';
                    } elseif ($daysSince > 30) {
                        $engagementBadge = 'badge-warning';
                        $engagementText = 'Moderate';
                    }
                    ?>
                    <tr>
                        <td><strong>#<?= $idx + 1 ?></strong></td>
                        <td><?= htmlspecialchars($customer['name']) ?></td>
                        <td class="text-right"><?= number_format($customer['order_count']) ?></td>
                        <td class="text-right"><strong>$<?= number_format($customer['lifetime_value_usd'], 2) ?></strong></td>
                        <td class="text-right"><?= date('M d, Y', strtotime($customer['last_order_date'])) ?></td>
                        <td class="text-right">
                            <span class="badge <?= $engagementBadge ?>"><?= $engagementText ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">👥</div>
            <div class="empty-state-text">No customer data for the selected period</div>
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
                label: 'Daily Revenue (USD)',
                data: <?= json_encode($trendRevenue) ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => '$' + value.toLocaleString()
                    }
                }
            }
        }
    });
}

// Order Type Chart
const orderTypeCtx = document.getElementById('orderTypeChart');
if (orderTypeCtx && <?= json_encode($orderTypeLabels) ?>.length > 0) {
    new Chart(orderTypeCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($orderTypeLabels) ?>,
            datasets: [{
                data: <?= json_encode($orderTypeValues) ?>,
                backgroundColor: ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Category Performance Chart
const categoryCtx = document.getElementById('categoryChart');
if (categoryCtx && <?= json_encode($categoryLabels) ?>.length > 0) {
    new Chart(categoryCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($categoryLabels) ?>,
            datasets: [{
                label: 'Revenue (USD)',
                data: <?= json_encode($categoryValues) ?>,
                backgroundColor: '#0ea5e9',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => '$' + value.toLocaleString()
                    }
                }
            }
        }
    });
}

// Receivables Aging Chart
const receivablesCtx = document.getElementById('receivablesChart');
if (receivablesCtx) {
    new Chart(receivablesCtx, {
        type: 'bar',
        data: {
            labels: ['0-30 Days', '31-60 Days', '61-90 Days', '90+ Days'],
            datasets: [{
                label: 'Outstanding Amount (USD)',
                data: [
                    <?= $receivables['current_30'] ?>,
                    <?= $receivables['days_31_60'] ?>,
                    <?= $receivables['days_61_90'] ?>,
                    <?= $receivables['over_90'] ?>
                ],
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#991b1b'],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => '$' + value.toLocaleString()
                    }
                }
            }
        }
    });
}
</script>

<?php sales_portal_render_layout_end(); ?>
