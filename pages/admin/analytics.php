<?php
/**
 * Analytics Dashboard - Comprehensive B2B Analytics with Advanced Features
 *
 * Features:
 * - Revenue trends & forecasting
 * - Sales rep performance & quota tracking
 * - Customer analytics (CLV, retention, segmentation)
 * - Product performance & profit margins
 * - Inventory intelligence
 * - Order type breakdown (company vs van stock)
 * - Time-based trends & patterns
 * - Financial deep dive
 * - Real-time alerts
 * - Comparative analytics (YoY, MoM)
 * - Export capabilities
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
$title = 'Advanced Analytics Dashboard';

// ========================================
// FILTER PARAMETERS
// ========================================

$period = $_GET['period'] ?? '30days';
$salesRepFilter = $_GET['sales_rep'] ?? '';
$orderTypeFilter = $_GET['order_type'] ?? ''; // company_order_request or van_stock_sale
$customerTierFilter = $_GET['customer_tier'] ?? ''; // high, medium, low

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

// Calculate comparison period (for YoY, MoM analysis)
$daysInPeriod = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
$comparisonFrom = date('Y-m-d', strtotime($dateFrom . " -{$daysInPeriod} days"));
$comparisonTo = date('Y-m-d', strtotime($dateFrom . ' -1 day'));

// Build WHERE clause for filters
$whereClause = '';
$params = [':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59'];
$comparisonParams = [':from' => $comparisonFrom, ':to' => $comparisonTo . ' 23:59:59'];

if ($salesRepFilter !== '') {
    $whereClause .= ' AND o.sales_rep_id = :sales_rep_id';
    $params[':sales_rep_id'] = (int)$salesRepFilter;
    $comparisonParams[':sales_rep_id'] = (int)$salesRepFilter;
}

if ($orderTypeFilter !== '') {
    $whereClause .= ' AND o.order_type = :order_type';
    $params[':order_type'] = $orderTypeFilter;
    $comparisonParams[':order_type'] = $orderTypeFilter;
}

if ($customerTierFilter !== '') {
    $whereClause .= ' AND c.customer_tier = :customer_tier';
    $params[':customer_tier'] = $customerTierFilter;
    $comparisonParams[':customer_tier'] = $customerTierFilter;
}

// ========================================
// REVENUE ANALYTICS (Current & Comparison)
// ========================================

$revenueQuery = "
    SELECT
        SUM(i.total_usd) as total_usd,
        SUM(i.total_lbp) as total_lbp,
        COUNT(*) as invoice_count,
        AVG(i.total_usd) as avg_invoice_usd,
        COUNT(DISTINCT o.customer_id) as unique_customers
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    $whereClause
";

$stmt = $pdo->prepare($revenueQuery);
$stmt->execute($params);
$revenue = $stmt->fetch(PDO::FETCH_ASSOC);

// Comparison period revenue
$stmt = $pdo->prepare($revenueQuery);
$stmt->execute($comparisonParams);
$revenueComparison = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate growth percentages
$revenueGrowth = 0;
if ($revenueComparison['total_usd'] > 0) {
    $revenueGrowth = (($revenue['total_usd'] - $revenueComparison['total_usd']) / $revenueComparison['total_usd']) * 100;
}

// Daily revenue trend
$revenueDailyStmt = $pdo->prepare("
    SELECT
        DATE(i.created_at) as date,
        SUM(i.total_usd) as revenue_usd,
        SUM(i.total_lbp) as revenue_lbp,
        COUNT(*) as invoice_count
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    $whereClause
    GROUP BY DATE(i.created_at)
    ORDER BY date ASC
");
$revenueDailyStmt->execute($params);
$revenueDailyData = $revenueDailyStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// ORDER TYPE BREAKDOWN
// ========================================

$orderTypeStmt = $pdo->prepare("
    SELECT
        o.order_type,
        COUNT(DISTINCT o.id) as order_count,
        SUM(i.total_usd) as revenue_usd,
        AVG(i.total_usd) as avg_order_value,
        COUNT(DISTINCT o.customer_id) as unique_customers
    FROM orders o
    INNER JOIN invoices i ON i.order_id = o.id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    " . ($salesRepFilter ? ' AND o.sales_rep_id = :sales_rep_id' : '') . "
    " . ($customerTierFilter ? ' AND c.customer_tier = :customer_tier' : '') . "
    GROUP BY o.order_type
    ORDER BY revenue_usd DESC
");
$orderTypeStmt->execute($salesRepFilter || $customerTierFilter ? $params : [':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$orderTypeData = $orderTypeStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// PRODUCT PERFORMANCE & PROFIT MARGINS
// ========================================

$productPerformanceStmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.wholesale_price_usd,
        p.cost_price_usd,
        SUM(oi.quantity) as units_sold,
        SUM(oi.quantity * oi.unit_price_usd) as revenue_usd,
        SUM(oi.quantity * COALESCE(p.cost_price_usd, 0)) as cost_usd,
        (SUM(oi.quantity * oi.unit_price_usd) - SUM(oi.quantity * COALESCE(p.cost_price_usd, 0))) as profit_usd,
        CASE
            WHEN SUM(oi.quantity * oi.unit_price_usd) > 0
            THEN ((SUM(oi.quantity * oi.unit_price_usd) - SUM(oi.quantity * COALESCE(p.cost_price_usd, 0))) / SUM(oi.quantity * oi.unit_price_usd)) * 100
            ELSE 0
        END as margin_percent,
        COUNT(DISTINCT oi.order_id) as order_count
    FROM products p
    INNER JOIN order_items oi ON oi.product_id = p.id
    INNER JOIN orders o ON o.id = oi.order_id
    INNER JOIN invoices i ON i.order_id = o.id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    $whereClause
    GROUP BY p.id
    ORDER BY revenue_usd DESC
    LIMIT 20
");
$productPerformanceStmt->execute($params);
$productData = $productPerformanceStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// CUSTOMER LIFETIME VALUE & ANALYTICS
// ========================================

$customerAnalyticsStmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.customer_tier,
        COUNT(DISTINCT o.id) as total_orders,
        SUM(i.total_usd) as lifetime_value_usd,
        AVG(i.total_usd) as avg_order_value_usd,
        MIN(i.created_at) as first_order_date,
        MAX(i.created_at) as last_order_date,
        DATEDIFF(CURDATE(), MAX(i.created_at)) as days_since_last_order,
        CASE
            WHEN COUNT(DISTINCT o.id) > 1
            THEN DATEDIFF(MAX(i.created_at), MIN(i.created_at)) / (COUNT(DISTINCT o.id) - 1)
            ELSE 0
        END as avg_days_between_orders
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    INNER JOIN invoices i ON i.order_id = o.id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    " . ($salesRepFilter ? ' AND o.sales_rep_id = :sales_rep_id' : '') . "
    " . ($orderTypeFilter ? ' AND o.order_type = :order_type' : '') . "
    " . ($customerTierFilter ? ' AND c.customer_tier = :customer_tier' : '') . "
    GROUP BY c.id
    ORDER BY lifetime_value_usd DESC
    LIMIT 20
");
$customerAnalyticsStmt->execute($params);
$customerData = $customerAnalyticsStmt->fetchAll(PDO::FETCH_ASSOC);

// Customer tier distribution
$tierDistributionStmt = $pdo->prepare("
    SELECT
        c.customer_tier,
        COUNT(DISTINCT c.id) as customer_count,
        SUM(i.total_usd) as revenue_usd,
        AVG(i.total_usd) as avg_order_value
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    INNER JOIN invoices i ON i.order_id = o.id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    " . ($salesRepFilter ? ' AND o.sales_rep_id = :sales_rep_id' : '') . "
    " . ($orderTypeFilter ? ' AND o.order_type = :order_type' : '') . "
    GROUP BY c.customer_tier
    ORDER BY revenue_usd DESC
");

// Build params for this specific query
$tierParams = [':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59'];
if ($salesRepFilter) {
    $tierParams[':sales_rep_id'] = (int)$salesRepFilter;
}
if ($orderTypeFilter) {
    $tierParams[':order_type'] = $orderTypeFilter;
}
$tierDistributionStmt->execute($tierParams);
$tierData = $tierDistributionStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// SALES REP QUOTA TRACKING
// ========================================

$currentYear = date('Y');
$currentMonth = date('m');

$quotaTrackingStmt = $pdo->prepare("
    SELECT
        u.id,
        u.name,
        COALESCE(sq.quota_usd, 0) as quota_usd,
        COALESCE(SUM(i.total_usd), 0) as actual_usd,
        COUNT(DISTINCT o.id) as order_count,
        COUNT(DISTINCT o.customer_id) as customer_count,
        CASE
            WHEN sq.quota_usd > 0
            THEN (COALESCE(SUM(i.total_usd), 0) / sq.quota_usd) * 100
            ELSE 0
        END as quota_achievement_percent
    FROM users u
    LEFT JOIN sales_quotas sq ON sq.sales_rep_id = u.id
        AND sq.year = :year
        AND sq.month = :month
    LEFT JOIN orders o ON o.sales_rep_id = u.id
    LEFT JOIN invoices i ON i.order_id = o.id
        AND i.created_at >= :from
        AND i.created_at <= :to
        AND i.status IN ('issued', 'paid')
    WHERE u.role = 'sales_rep'
    " . ($salesRepFilter ? ' AND u.id = :sales_rep_id' : '') . "
    GROUP BY u.id, sq.quota_usd
    ORDER BY actual_usd DESC
");

$quotaParams = [
    ':year' => $currentYear,
    ':month' => $currentMonth,
    ':from' => $dateFrom,
    ':to' => $dateTo . ' 23:59:59'
];
if ($salesRepFilter) {
    $quotaParams[':sales_rep_id'] = (int)$salesRepFilter;
}
$quotaTrackingStmt->execute($quotaParams);
$quotaData = $quotaTrackingStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// INVENTORY INTELLIGENCE
// ========================================

$inventoryIntelligence = $pdo->query("
    SELECT
        COUNT(*) as total_products,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
        SUM(CASE WHEN quantity_on_hand <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand > 0 AND quantity_on_hand <= min_quantity THEN 1 ELSE 0 END) as low_stock,
        SUM(quantity_on_hand * wholesale_price_usd) as inventory_value_usd,
        SUM(quantity_on_hand * COALESCE(cost_price_usd, 0)) as inventory_cost_usd
    FROM products
")->fetch(PDO::FETCH_ASSOC);

// Fast vs Slow Moving Products
$fastSlowMovingStmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.quantity_on_hand,
        SUM(oi.quantity) as units_sold,
        CASE
            WHEN p.quantity_on_hand > 0
            THEN (SUM(oi.quantity) / p.quantity_on_hand)
            ELSE SUM(oi.quantity)
        END as turnover_ratio,
        CASE
            WHEN (SUM(oi.quantity) / GREATEST(p.quantity_on_hand, 1)) > 2 THEN 'Fast Moving'
            WHEN (SUM(oi.quantity) / GREATEST(p.quantity_on_hand, 1)) > 0.5 THEN 'Medium Moving'
            ELSE 'Slow Moving'
        END as movement_category
    FROM products p
    LEFT JOIN order_items oi ON oi.product_id = p.id
    LEFT JOIN orders o ON o.id = oi.order_id
    LEFT JOIN invoices i ON i.order_id = o.id
        AND i.created_at >= :from
        AND i.created_at <= :to
        AND i.status IN ('issued', 'paid')
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY turnover_ratio DESC
    LIMIT 30
");
$fastSlowMovingStmt->execute([':from' => $dateFrom, ':to' => $dateTo . ' 23:59:59']);
$inventoryMovement = $fastSlowMovingStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// ORDER ANALYTICS
// ========================================

$orderAnalyticsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_orders,
        AVG(CASE
            WHEN invoice_created IS NOT NULL
            THEN TIMESTAMPDIFF(HOUR, order_created, invoice_created)
            ELSE NULL
        END) as avg_processing_hours,
        SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        AVG(item_count) as avg_items_per_order,
        SUM(CASE WHEN total_value < 500 THEN 1 ELSE 0 END) as small_orders,
        SUM(CASE WHEN total_value >= 500 AND total_value < 2000 THEN 1 ELSE 0 END) as medium_orders,
        SUM(CASE WHEN total_value >= 2000 THEN 1 ELSE 0 END) as large_orders
    FROM (
        SELECT
            o.id,
            o.status as order_status,
            o.created_at as order_created,
            i.created_at as invoice_created,
            COUNT(oi.id) as item_count,
            SUM(oi.quantity * oi.unit_price_usd) as total_value
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        INNER JOIN customers c ON c.id = o.customer_id
        WHERE o.created_at >= :from AND o.created_at <= :to
        $whereClause
        GROUP BY o.id
    ) AS order_summary
");
$orderAnalyticsStmt->execute($params);
$orderStats = $orderAnalyticsStmt->fetch(PDO::FETCH_ASSOC);

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
        COUNT(*) as invoice_count,
        SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) > 60 THEN (i.total_usd - COALESCE(paid.paid_usd, 0)) ELSE 0 END) as overdue_60_plus
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id,
               SUM(amount_usd) AS paid_usd
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
    AND (i.total_usd - COALESCE(paid.paid_usd, 0) > 0.01)
")->fetch(PDO::FETCH_ASSOC);

// Total profit calculation
$totalProfit = 0;
$totalCost = 0;
foreach ($productData as $product) {
    $totalProfit += $product['profit_usd'];
    $totalCost += $product['cost_usd'];
}
$overallMargin = $revenue['total_usd'] > 0 ? ($totalProfit / $revenue['total_usd']) * 100 : 0;

// Payment method distribution
$paymentMethodsStmt = $pdo->prepare("
    SELECT
        p.method,
        COUNT(*) as payment_count,
        SUM(p.amount_usd) as total_usd
    FROM payments p
    INNER JOIN invoices i ON i.id = p.invoice_id
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE p.received_at >= :from AND p.received_at <= :to
    $whereClause
    GROUP BY p.method
    ORDER BY total_usd DESC
");
$paymentMethodsStmt->execute($params);
$paymentData = $paymentMethodsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// TIME-BASED TRENDS
// ========================================

// Day of week analysis
$dayOfWeekStmt = $pdo->prepare("
    SELECT
        DAYNAME(i.created_at) as day_name,
        DAYOFWEEK(i.created_at) as day_num,
        COUNT(*) as order_count,
        SUM(i.total_usd) as revenue_usd
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    $whereClause
    GROUP BY day_name, day_num
    ORDER BY day_num
");
$dayOfWeekStmt->execute($params);
$dayOfWeekData = $dayOfWeekStmt->fetchAll(PDO::FETCH_ASSOC);

// Hour of day analysis (if you have enough data)
$hourOfDayStmt = $pdo->prepare("
    SELECT
        HOUR(i.created_at) as hour,
        COUNT(*) as order_count,
        SUM(i.total_usd) as revenue_usd
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE i.created_at >= :from AND i.created_at <= :to
    AND i.status IN ('issued', 'paid')
    $whereClause
    GROUP BY hour
    ORDER BY hour
");
$hourOfDayStmt->execute($params);
$hourOfDayData = $hourOfDayStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// REAL-TIME ALERTS
// ========================================

$alerts = [];

// Check today's sales vs average
$todaySales = $pdo->query("
    SELECT COALESCE(SUM(total_usd), 0) as total
    FROM invoices
    WHERE DATE(created_at) = CURDATE()
    AND status IN ('issued', 'paid')
")->fetchColumn();

$avgDailySales = $revenue['total_usd'] / max($daysInPeriod, 1);

if ($todaySales < ($avgDailySales * 0.7)) {
    $alerts[] = [
        'type' => 'warning',
        'title' => 'Low Sales Today',
        'message' => sprintf('Today\'s sales ($%.2f) are 30%% below average ($%.2f)', $todaySales, $avgDailySales)
    ];
}

// Low stock alerts
if ($inventoryIntelligence['low_stock'] > 0) {
    $alerts[] = [
        'type' => 'danger',
        'title' => 'Low Stock Alert',
        'message' => sprintf('%d products are at or below minimum stock levels', $inventoryIntelligence['low_stock'])
    ];
}

// Overdue receivables
if ($outstandingAR['overdue_60_plus'] > 0) {
    $alerts[] = [
        'type' => 'danger',
        'title' => 'Overdue Receivables',
        'message' => sprintf('$%.2f in receivables are 60+ days overdue', $outstandingAR['overdue_60_plus'])
    ];
}

// Get all sales reps and customer tiers for filters
$salesRepsQuery = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$allSalesReps = $salesRepsQuery->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// RENDER PAGE
// ========================================

admin_render_layout_start([
    'title' => 'Admin · ' . $title,
    'heading' => $title,
    'subtitle' => sprintf('Period: %s to %s | %d days', $dateFrom, $dateTo, ceil($daysInPeriod)),
    'active' => 'analytics',
    'user' => $user,
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* Enhanced Filter Bar */
        .filter-bar {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 6px; min-width: 180px; }
        .filter-group label { font-size: 0.85rem; color: var(--muted); font-weight: 600; }
        .period-selector { display: flex; gap: 6px; flex-wrap: wrap; }
        .period-btn {
            padding: 8px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .period-btn:hover { background: var(--bg-panel-alt); color: var(--text); }
        .period-btn.active { background: var(--accent); color: white; border-color: var(--accent); }
        select.filter-select {
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-panel);
            color: var(--text);
            font-size: 0.9rem;
        }
        select.filter-select:focus { outline: none; border-color: var(--accent); }

        /* Alerts Section */
        .alerts-section { margin-bottom: 24px; }
        .alert-card {
            background: var(--bg-panel);
            border: 1px solid;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-card.warning { border-color: #f59e0b; background: rgba(245, 158, 11, 0.05); }
        .alert-card.danger { border-color: #ef4444; background: rgba(239, 68, 68, 0.05); }
        .alert-card.success { border-color: #10b981; background: rgba(16, 185, 129, 0.05); }
        .alert-icon { font-size: 1.5rem; }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 700; font-size: 0.95rem; color: var(--text); margin-bottom: 4px; }
        .alert-message { font-size: 0.85rem; color: var(--muted); }

        /* Enhanced Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .metric-card {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        .metric-card:hover {
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .metric-label {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 6px;
        }
        .metric-sub { font-size: 0.85rem; color: var(--muted); }
        .metric-growth {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 6px;
        }
        .metric-growth.positive { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .metric-growth.negative { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
        .metric-value.success { color: #10b981; }
        .metric-value.warning { color: #f59e0b; }
        .metric-value.danger { color: #ef4444; }
        .metric-value.info { color: #1f6feb; }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .chart-container {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }
        .chart-container.full-width { grid-column: 1 / -1; }
        .chart-title {
            font-size: 1.1rem;
            margin-bottom: 20px;
            color: var(--text);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        canvas { max-height: 320px; }

        /* Tables */
        .table-container {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .table-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .table-header h3 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f9fafb; }
        th, td { padding: 14px 16px; text-align: left; }
        th {
            color: var(--muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
        }
        td {
            color: var(--text);
            font-size: 0.9rem;
            border-bottom: 1px solid #f3f4f6;
        }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: #f9fafb; cursor: pointer; }
        tbody tr:last-child td { border-bottom: none; }

        .progress-bar-container {
            width: 100%;
            background: #e5e7eb;
            border-radius: 999px;
            height: 8px;
            overflow: hidden;
            margin-top: 4px;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 999px;
            transition: width 0.3s;
        }
        .progress-bar.warning { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .progress-bar.danger { background: linear-gradient(90deg, #ef4444, #dc2626); }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .badge-fast { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .badge-medium { background: rgba(245, 158, 11, 0.15); color: #d97706; }
        .badge-slow { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
        .badge-high { background: rgba(31, 111, 235, 0.15); color: #1d4ed8; }
        .badge-low { background: rgba(156, 163, 175, 0.15); color: #4b5563; }

        .export-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .export-btn:hover {
            background: #1557c9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(31, 111, 235, 0.3);
        }

        @media (max-width: 768px) {
            .charts-grid { grid-template-columns: 1fr; }
            .metrics-grid { grid-template-columns: 1fr; }
        }
    </style>'
]);
?>

<!-- REAL-TIME ALERTS -->
<?php if (!empty($alerts)): ?>
<div class="alerts-section">
    <?php foreach ($alerts as $alert): ?>
    <div class="alert-card <?= htmlspecialchars($alert['type']) ?>">
        <div class="alert-icon">
            <?php if ($alert['type'] === 'danger'): ?>🚨
            <?php elseif ($alert['type'] === 'warning'): ?>⚠️
            <?php else: ?>✅<?php endif; ?>
        </div>
        <div class="alert-content">
            <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
            <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- FILTER BAR -->
<div class="filter-bar">
    <div class="filter-group">
        <label>Time Period</label>
        <div class="period-selector">
            <a href="?period=today<?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>"
               class="period-btn <?= $period === 'today' ? 'active' : '' ?>">Today</a>
            <a href="?period=7days<?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>"
               class="period-btn <?= $period === '7days' ? 'active' : '' ?>">7 Days</a>
            <a href="?period=30days<?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>"
               class="period-btn <?= $period === '30days' ? 'active' : '' ?>">30 Days</a>
            <a href="?period=90days<?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>"
               class="period-btn <?= $period === '90days' ? 'active' : '' ?>">90 Days</a>
            <a href="?period=thismonth<?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>"
               class="period-btn <?= $period === 'thismonth' ? 'active' : '' ?>">This Month</a>
            <a href="?period=thisyear<?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>"
               class="period-btn <?= $period === 'thisyear' ? 'active' : '' ?>">This Year</a>
        </div>
    </div>

    <div class="filter-group">
        <label for="salesRepFilter">Sales Representative</label>
        <select id="salesRepFilter" class="filter-select" onchange="window.location.href='?period=<?= $period ?>&sales_rep=' + this.value + '<?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?><?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>'">
            <option value="">All Sales Reps</option>
            <?php foreach ($allSalesReps as $rep): ?>
                <option value="<?= $rep['id'] ?>" <?= $salesRepFilter == $rep['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rep['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <label for="orderTypeFilter">Order Type</label>
        <select id="orderTypeFilter" class="filter-select" onchange="window.location.href='?period=<?= $period ?><?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?>&order_type=' + this.value + '<?= $customerTierFilter ? '&customer_tier=' . $customerTierFilter : '' ?>'">
            <option value="">All Order Types</option>
            <option value="company_order_request" <?= $orderTypeFilter === 'company_order_request' ? 'selected' : '' ?>>Company Orders</option>
            <option value="van_stock_sale" <?= $orderTypeFilter === 'van_stock_sale' ? 'selected' : '' ?>>Van Stock Sales</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="customerTierFilter">Customer Tier</label>
        <select id="customerTierFilter" class="filter-select" onchange="window.location.href='?period=<?= $period ?><?= $salesRepFilter ? '&sales_rep=' . $salesRepFilter : '' ?><?= $orderTypeFilter ? '&order_type=' . $orderTypeFilter : '' ?>&customer_tier=' + this.value">
            <option value="">All Tiers</option>
            <option value="high" <?= $customerTierFilter === 'high' ? 'selected' : '' ?>>High Tier</option>
            <option value="medium" <?= $customerTierFilter === 'medium' ? 'selected' : '' ?>>Medium Tier</option>
            <option value="low" <?= $customerTierFilter === 'low' ? 'selected' : '' ?>>Low Tier</option>
        </select>
    </div>
</div>

<!-- KEY METRICS WITH GROWTH INDICATORS -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-label">💰 Total Revenue (USD)</div>
        <div class="metric-value success">$<?= number_format($revenue['total_usd'] ?? 0, 2) ?></div>
        <div class="metric-sub"><?= number_format($revenue['invoice_count'] ?? 0) ?> invoices</div>
        <?php if ($revenueGrowth != 0): ?>
        <span class="metric-growth <?= $revenueGrowth > 0 ? 'positive' : 'negative' ?>">
            <?= $revenueGrowth > 0 ? '↑' : '↓' ?> <?= abs(number_format($revenueGrowth, 1)) ?>% vs previous period
        </span>
        <?php endif; ?>
    </div>

    <div class="metric-card">
        <div class="metric-label">📊 Gross Profit</div>
        <div class="metric-value success">$<?= number_format($totalProfit, 2) ?></div>
        <div class="metric-sub"><?= number_format($overallMargin, 1) ?>% margin</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">📈 Average Invoice</div>
        <div class="metric-value info">$<?= number_format($revenue['avg_invoice_usd'] ?? 0, 2) ?></div>
        <div class="metric-sub">USD per invoice</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">👥 Unique Customers</div>
        <div class="metric-value"><?= number_format($revenue['unique_customers'] ?? 0) ?></div>
        <div class="metric-sub">Served in period</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">💳 Outstanding AR</div>
        <div class="metric-value warning">$<?= number_format($outstandingAR['outstanding_usd'] ?? 0, 2) ?></div>
        <div class="metric-sub"><?= $outstandingAR['invoice_count'] ?? 0 ?> unpaid invoices</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">⏱️ DSO (Days)</div>
        <div class="metric-value <?= $dso > 45 ? 'danger' : ($dso > 30 ? 'warning' : 'success') ?>"><?= $dso ?></div>
        <div class="metric-sub">Average collection time</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">📦 Inventory Value</div>
        <div class="metric-value">$<?= number_format($inventoryIntelligence['inventory_value_usd'] ?? 0, 0) ?></div>
        <div class="metric-sub"><?= $inventoryIntelligence['active_products'] ?? 0 ?> active products</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">⚠️ Stock Alerts</div>
        <div class="metric-value <?= ($inventoryIntelligence['low_stock'] ?? 0) > 0 ? 'danger' : 'success' ?>">
            <?= $inventoryIntelligence['low_stock'] ?? 0 ?>
        </div>
        <div class="metric-sub"><?= $inventoryIntelligence['out_of_stock'] ?? 0 ?> out of stock</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">📦 Total Orders</div>
        <div class="metric-value"><?= number_format($orderStats['total_orders'] ?? 0) ?></div>
        <div class="metric-sub"><?= number_format($orderStats['avg_items_per_order'] ?? 0, 1) ?> items avg</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">⏰ Avg Processing Time</div>
        <div class="metric-value info"><?= number_format($orderStats['avg_processing_hours'] ?? 0, 1) ?>h</div>
        <div class="metric-sub">Order to invoice</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">🛑 Overdue 60+ Days</div>
        <div class="metric-value danger">$<?= number_format($outstandingAR['overdue_60_plus'] ?? 0, 2) ?></div>
        <div class="metric-sub">Requires attention</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">📉 Inventory Turnover</div>
        <div class="metric-value info"><?= number_format(count(array_filter($inventoryMovement, fn($p) => $p['movement_category'] === 'Fast Moving'))) ?></div>
        <div class="metric-sub">Fast moving products</div>
    </div>
</div>

<!-- CHARTS SECTION -->
<div class="charts-grid">
    <!-- Revenue Trend Chart -->
    <div class="chart-container full-width">
        <h3 class="chart-title">📈 Revenue Trend Over Time</h3>
        <canvas id="revenueTrendChart"></canvas>
    </div>

    <!-- Order Type Breakdown -->
    <div class="chart-container">
        <h3 class="chart-title">📦 Order Type Distribution</h3>
        <canvas id="orderTypeChart"></canvas>
    </div>

    <!-- Customer Tier Performance -->
    <div class="chart-container">
        <h3 class="chart-title">👥 Revenue by Customer Tier</h3>
        <canvas id="customerTierChart"></canvas>
    </div>

    <!-- Day of Week Analysis -->
    <div class="chart-container">
        <h3 class="chart-title">📅 Sales by Day of Week</h3>
        <canvas id="dayOfWeekChart"></canvas>
    </div>

    <!-- Payment Methods -->
    <div class="chart-container">
        <h3 class="chart-title">💳 Payment Method Distribution</h3>
        <canvas id="paymentMethodChart"></canvas>
    </div>
</div>

<!-- SALES REP QUOTA TRACKING TABLE -->
<div class="table-container">
    <div class="table-header">
        <h3>🎯 Sales Rep Quota Achievement (<?= date('F Y') ?>)</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Sales Rep</th>
                <th style="text-align: right;">Quota (USD)</th>
                <th style="text-align: right;">Actual (USD)</th>
                <th style="text-align: right;">Achievement</th>
                <th style="text-align: center;">Progress</th>
                <th style="text-align: right;">Orders</th>
                <th style="text-align: right;">Customers</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($quotaData as $quota):
                $achievement = $quota['quota_achievement_percent'];
                $progressClass = $achievement >= 100 ? 'success' : ($achievement >= 70 ? 'warning' : 'danger');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($quota['name']) ?></strong></td>
                <td style="text-align: right;">$<?= number_format($quota['quota_usd'], 2) ?></td>
                <td style="text-align: right;"><strong style="color: #10b981;">$<?= number_format($quota['actual_usd'], 2) ?></strong></td>
                <td style="text-align: right;"><strong><?= number_format($achievement, 1) ?>%</strong></td>
                <td style="text-align: center;">
                    <div class="progress-bar-container">
                        <div class="progress-bar <?= $progressClass ?>" style="width: <?= min($achievement, 100) ?>%"></div>
                    </div>
                </td>
                <td style="text-align: right;"><?= $quota['order_count'] ?></td>
                <td style="text-align: right;"><?= $quota['customer_count'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($quotaData)): ?>
            <tr>
                <td colspan="7" style="text-align: center; color: var(--muted); padding: 40px;">No quota data available</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- PRODUCT PERFORMANCE WITH PROFIT MARGINS -->
<div class="table-container">
    <div class="table-header">
        <h3>🏆 Top Products by Revenue & Profitability</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>SKU</th>
                <th>Product</th>
                <th style="text-align: right;">Units Sold</th>
                <th style="text-align: right;">Revenue (USD)</th>
                <th style="text-align: right;">Cost (USD)</th>
                <th style="text-align: right;">Profit (USD)</th>
                <th style="text-align: right;">Margin %</th>
            </tr>
        </thead>
        <tbody>
            <?php $rank = 1; foreach ($productData as $product): ?>
            <tr>
                <td><strong>#<?= $rank++ ?></strong></td>
                <td><?= htmlspecialchars($product['sku']) ?></td>
                <td><?= htmlspecialchars($product['item_name']) ?></td>
                <td style="text-align: right;"><?= number_format($product['units_sold'], 0) ?></td>
                <td style="text-align: right;"><strong>$<?= number_format($product['revenue_usd'], 2) ?></strong></td>
                <td style="text-align: right;">$<?= number_format($product['cost_usd'], 2) ?></td>
                <td style="text-align: right;"><strong style="color: #10b981;">$<?= number_format($product['profit_usd'], 2) ?></strong></td>
                <td style="text-align: right;">
                    <strong style="color: <?= $product['margin_percent'] >= 30 ? '#10b981' : ($product['margin_percent'] >= 15 ? '#f59e0b' : '#ef4444') ?>">
                        <?= number_format($product['margin_percent'], 1) ?>%
                    </strong>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($productData)): ?>
            <tr>
                <td colspan="8" style="text-align: center; color: var(--muted); padding: 40px;">No product data available</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- CUSTOMER LIFETIME VALUE TABLE -->
<div class="table-container">
    <div class="table-header">
        <h3>💎 Top Customers by Lifetime Value</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Customer</th>
                <th>Tier</th>
                <th style="text-align: right;">Lifetime Value</th>
                <th style="text-align: right;">Orders</th>
                <th style="text-align: right;">Avg Order</th>
                <th style="text-align: right;">Days Since Last</th>
                <th style="text-align: right;">Avg Days Between</th>
            </tr>
        </thead>
        <tbody>
            <?php $rank = 1; foreach ($customerData as $customer): ?>
            <tr>
                <td><strong>#<?= $rank++ ?></strong></td>
                <td><strong><?= htmlspecialchars($customer['name']) ?></strong></td>
                <td>
                    <span class="badge badge-<?= strtolower($customer['customer_tier'] ?? 'medium') ?>">
                        <?= strtoupper($customer['customer_tier'] ?? 'MEDIUM') ?>
                    </span>
                </td>
                <td style="text-align: right;"><strong style="color: #10b981;">$<?= number_format($customer['lifetime_value_usd'], 2) ?></strong></td>
                <td style="text-align: right;"><?= $customer['total_orders'] ?></td>
                <td style="text-align: right;">$<?= number_format($customer['avg_order_value_usd'], 2) ?></td>
                <td style="text-align: right;">
                    <span style="color: <?= $customer['days_since_last_order'] > 60 ? '#ef4444' : '#10b981' ?>">
                        <?= $customer['days_since_last_order'] ?> days
                    </span>
                </td>
                <td style="text-align: right;"><?= number_format($customer['avg_days_between_orders'], 0) ?> days</td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($customerData)): ?>
            <tr>
                <td colspan="8" style="text-align: center; color: var(--muted); padding: 40px;">No customer data available</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- INVENTORY MOVEMENT TABLE -->
<div class="table-container">
    <div class="table-header">
        <h3>📊 Inventory Movement Analysis</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product</th>
                <th style="text-align: right;">On Hand</th>
                <th style="text-align: right;">Units Sold</th>
                <th style="text-align: right;">Turnover Ratio</th>
                <th style="text-align: center;">Category</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventoryMovement as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['sku']) ?></td>
                <td><?= htmlspecialchars($item['item_name']) ?></td>
                <td style="text-align: right;"><?= number_format($item['quantity_on_hand'], 0) ?></td>
                <td style="text-align: right;"><?= number_format($item['units_sold'], 0) ?></td>
                <td style="text-align: right;"><strong><?= number_format($item['turnover_ratio'], 2) ?></strong></td>
                <td style="text-align: center;">
                    <span class="badge badge-<?= strtolower(str_replace(' ', '-', $item['movement_category'])) ?>">
                        <?= htmlspecialchars($item['movement_category']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($inventoryMovement)): ?>
            <tr>
                <td colspan="6" style="text-align: center; color: var(--muted); padding: 40px;">No inventory data available</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Chart.js Configuration
Chart.defaults.color = '#6b7280';
Chart.defaults.borderColor = '#e5e7eb';
Chart.defaults.font.family = '"Segoe UI", system-ui, -apple-system, sans-serif';

// Revenue Trend Chart
const revenueTrendCtx = document.getElementById('revenueTrendChart').getContext('2d');
new Chart(revenueTrendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($revenueDailyData, 'date')) ?>,
        datasets: [{
            label: 'Revenue (USD)',
            data: <?= json_encode(array_column($revenueDailyData, 'revenue_usd')) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true,
            borderWidth: 3,
            pointBackgroundColor: '#10b981',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { labels: { color: '#111827', font: { size: 12, weight: '600' } } },
            tooltip: {
                backgroundColor: '#ffffff',
                titleColor: '#111827',
                bodyColor: '#6b7280',
                borderColor: '#e5e7eb',
                borderWidth: 1
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: '#6b7280', font: { size: 11 } },
                grid: { color: '#e5e7eb', drawBorder: false }
            },
            x: {
                ticks: { color: '#6b7280', font: { size: 11 } },
                grid: { color: '#e5e7eb', drawBorder: false }
            }
        }
    }
});

// Order Type Chart
const orderTypeCtx = document.getElementById('orderTypeChart').getContext('2d');
new Chart(orderTypeCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($o) => ucwords(str_replace('_', ' ', $o['order_type'])), $orderTypeData)) ?>,
        datasets: [{
            data: <?= json_encode(array_column($orderTypeData, 'revenue_usd')) ?>,
            backgroundColor: ['#10b981', '#1f6feb', '#f59e0b', '#ef4444']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom', labels: { color: '#111827', font: { size: 11, weight: '600' }, padding: 10 } }
        }
    }
});

// Customer Tier Chart
const customerTierCtx = document.getElementById('customerTierChart').getContext('2d');
new Chart(customerTierCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($t) => strtoupper($t['customer_tier'] ?? 'UNKNOWN'), $tierData)) ?>,
        datasets: [{
            label: 'Revenue (USD)',
            data: <?= json_encode(array_column($tierData, 'revenue_usd')) ?>,
            backgroundColor: ['#1f6feb', '#10b981', '#9ca3af']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { labels: { color: '#111827', font: { size: 12, weight: '600' } } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { color: '#6b7280' }, grid: { color: '#e5e7eb' } },
            x: { ticks: { color: '#6b7280' }, grid: { color: '#e5e7eb' } }
        }
    }
});

// Day of Week Chart
const dayOfWeekCtx = document.getElementById('dayOfWeekChart').getContext('2d');
new Chart(dayOfWeekCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($dayOfWeekData, 'day_name')) ?>,
        datasets: [{
            label: 'Revenue (USD)',
            data: <?= json_encode(array_column($dayOfWeekData, 'revenue_usd')) ?>,
            backgroundColor: '#10b981'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { labels: { color: '#111827', font: { size: 12, weight: '600' } } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { color: '#6b7280' }, grid: { color: '#e5e7eb' } },
            x: { ticks: { color: '#6b7280' }, grid: { color: '#e5e7eb' } }
        }
    }
});

// Payment Method Chart
const paymentMethodCtx = document.getElementById('paymentMethodChart').getContext('2d');
new Chart(paymentMethodCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_map(fn($p) => ucwords(str_replace('_', ' ', $p['method'])), $paymentData)) ?>,
        datasets: [{
            data: <?= json_encode(array_column($paymentData, 'total_usd')) ?>,
            backgroundColor: ['#10b981', '#1f6feb', '#f59e0b', '#ef4444', '#8b5cf6']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom', labels: { color: '#111827', font: { size: 11, weight: '600' }, padding: 10 } }
        }
    }
});
</script>

<?php admin_render_layout_end(); ?>
