<?php
/**
 * System Statistics Page
 *
 * Features:
 * - Overall system statistics
 * - Database metrics
 * - Activity overview
 * - Growth trends
 * - System health indicators
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
$title = 'Admin · System Statistics';

// ========================================
// DATABASE STATISTICS
// ========================================

// Products
$productStats = $pdo->query("
    SELECT
        COUNT(*) as total_products,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
        SUM(quantity_on_hand) as total_stock_units,
        SUM(quantity_on_hand * sale_price_usd) as inventory_value_usd,
        SUM(CASE WHEN quantity_on_hand <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand <= min_quantity THEN 1 ELSE 0 END) as low_stock
    FROM products
")->fetch(PDO::FETCH_ASSOC);

// Customers
$customerStats = $pdo->query("
    SELECT
        COUNT(*) as total_customers,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_customers,
        SUM(CASE WHEN assigned_sales_rep_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_customers
    FROM customers
")->fetch(PDO::FETCH_ASSOC);

// Orders
$orderStats = $pdo->query("
    SELECT
        COUNT(*) as total_orders,
        COALESCE(SUM(i.total_usd), 0) as total_order_value_usd,
        COALESCE(AVG(i.total_usd), 0) as avg_order_value_usd
    FROM orders o
    LEFT JOIN invoices i ON i.order_id = o.id
")->fetch(PDO::FETCH_ASSOC);

// Invoices
$invoiceStats = $pdo->query("
    SELECT
        COUNT(*) as total_invoices,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_invoices,
        SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) as issued_invoices,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
        SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) as voided_invoices,
        COALESCE(SUM(total_usd), 0) as total_invoiced_usd
    FROM invoices
")->fetch(PDO::FETCH_ASSOC);

// Payments
$paymentStats = $pdo->query("
    SELECT
        COUNT(*) as total_payments,
        COALESCE(SUM(amount_usd), 0) as total_collected_usd,
        COALESCE(SUM(amount_lbp), 0) as total_collected_lbp
    FROM payments
")->fetch(PDO::FETCH_ASSOC);

// Outstanding AR
$arStats = $pdo->query("
    SELECT
        COALESCE(SUM(i.total_usd - COALESCE(paid.paid_usd, 0)), 0) as outstanding_usd,
        COALESCE(SUM(i.total_lbp - COALESCE(paid.paid_lbp, 0)), 0) as outstanding_lbp,
        COUNT(*) as unpaid_invoice_count
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id,
               SUM(amount_usd) as paid_usd,
               SUM(amount_lbp) as paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
    AND (i.total_usd - COALESCE(paid.paid_usd, 0) > 0.01
         OR i.total_lbp - COALESCE(paid.paid_lbp, 0) > 0.01)
")->fetch(PDO::FETCH_ASSOC);

// Users
$userStats = $pdo->query("
    SELECT
        COUNT(*) as total_users,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
        SUM(CASE WHEN role = 'sales_rep' THEN 1 ELSE 0 END) as sales_rep_count,
        SUM(CASE WHEN role = 'warehouse' THEN 1 ELSE 0 END) as warehouse_count,
        SUM(CASE WHEN role = 'accountant' THEN 1 ELSE 0 END) as accountant_count,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
    FROM users
")->fetch(PDO::FETCH_ASSOC);

// Recent Activity (Last 30 Days)
$recentActivity = $pdo->query("
    SELECT
        COUNT(DISTINCT o.id) as orders_last_30_days,
        COUNT(DISTINCT i.id) as invoices_last_30_days,
        COUNT(DISTINCT p.id) as payments_last_30_days,
        COALESCE(SUM(p.amount_usd), 0) as revenue_last_30_days
    FROM orders o
    LEFT JOIN invoices i ON i.order_id = o.id AND i.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    LEFT JOIN payments p ON p.invoice_id = i.id AND p.received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch(PDO::FETCH_ASSOC);

// Warehouse Movements
$warehouseStats = $pdo->query("
    SELECT
        COUNT(*) as total_movements,
        SUM(CASE WHEN movement_type = 'in' THEN 1 ELSE 0 END) as movements_in,
        SUM(CASE WHEN movement_type = 'out' THEN 1 ELSE 0 END) as movements_out,
        SUM(CASE WHEN movement_type = 'adjustment' THEN 1 ELSE 0 END) as adjustments
    FROM warehouse_movements
")->fetch(PDO::FETCH_ASSOC);

// Calculate collection rate
$collectionRate = $invoiceStats['total_invoiced_usd'] > 0
    ? ($paymentStats['total_collected_usd'] / $invoiceStats['total_invoiced_usd']) * 100
    : 0;

admin_render_layout_start([
    'title' => $title,
    'heading' => 'System Statistics',
    'subtitle' => 'Overview of system-wide metrics and performance indicators.',
    'active' => 'stats',
    'user' => $user,
]);
?>

<!-- Overview Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #1f6feb 0%, #0c4ab3 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Total Revenue</div>
        <div style="font-size: 2rem; font-weight: 700; margin-bottom: 4px;">$<?= number_format($paymentStats['total_collected_usd'], 0) ?></div>
        <div style="font-size: 0.85rem; opacity: 0.9;"><?= number_format($paymentStats['total_payments']) ?> payments</div>
    </div>

    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Total Orders</div>
        <div style="font-size: 2rem; font-weight: 700; margin-bottom: 4px;"><?= number_format($orderStats['total_orders']) ?></div>
        <div style="font-size: 0.85rem; opacity: 0.9;">Avg: $<?= number_format($orderStats['avg_order_value_usd'], 0) ?></div>
    </div>

    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Total Customers</div>
        <div style="font-size: 2rem; font-weight: 700; margin-bottom: 4px;"><?= number_format($customerStats['total_customers']) ?></div>
        <div style="font-size: 0.85rem; opacity: 0.9;"><?= number_format($customerStats['active_customers']) ?> active</div>
    </div>

    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Outstanding AR</div>
        <div style="font-size: 2rem; font-weight: 700; margin-bottom: 4px;">$<?= number_format($arStats['outstanding_usd'], 0) ?></div>
        <div style="font-size: 0.85rem; opacity: 0.9;"><?= number_format($arStats['unpaid_invoice_count']) ?> invoices</div>
    </div>
</div>

<!-- Recent Activity (Last 30 Days) -->
<div class="card" style="margin-bottom: 30px;">
    <h2 style="margin: 0 0 20px; font-size: 1.4rem;">Recent Activity (Last 30 Days)</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
        <div style="padding: 15px; background: #f9fafb; border-radius: 8px;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 6px;">Orders</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #111827;"><?= number_format($recentActivity['orders_last_30_days']) ?></div>
        </div>

        <div style="padding: 15px; background: #f9fafb; border-radius: 8px;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 6px;">Invoices</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #111827;"><?= number_format($recentActivity['invoices_last_30_days']) ?></div>
        </div>

        <div style="padding: 15px; background: #f9fafb; border-radius: 8px;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 6px;">Payments</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #111827;"><?= number_format($recentActivity['payments_last_30_days']) ?></div>
        </div>

        <div style="padding: 15px; background: #f9fafb; border-radius: 8px;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 6px;">Revenue</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #10b981;">$<?= number_format($recentActivity['revenue_last_30_days'], 0) ?></div>
        </div>
    </div>
</div>

<!-- Database Statistics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px;">

    <!-- Products -->
    <div class="card">
        <h2 style="margin: 0 0 15px; font-size: 1.2rem; color: #1f6feb;">Products</h2>
        <div style="display: grid; gap: 10px;">
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Total Products:</span>
                <strong><?= number_format($productStats['total_products']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Active:</span>
                <strong style="color: #10b981;"><?= number_format($productStats['active_products']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Total Stock Units:</span>
                <strong><?= number_format($productStats['total_stock_units'], 2) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Inventory Value:</span>
                <strong style="color: #10b981;">$<?= number_format($productStats['inventory_value_usd'], 0) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Out of Stock:</span>
                <strong style="color: #ef4444;"><?= number_format($productStats['out_of_stock']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                <span style="color: #6b7280;">Low Stock:</span>
                <strong style="color: #f59e0b;"><?= number_format($productStats['low_stock']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Invoices -->
    <div class="card">
        <h2 style="margin: 0 0 15px; font-size: 1.2rem; color: #8b5cf6;">Invoices</h2>
        <div style="display: grid; gap: 10px;">
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Total Invoices:</span>
                <strong><?= number_format($invoiceStats['total_invoices']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Draft:</span>
                <strong style="color: #f59e0b;"><?= number_format($invoiceStats['draft_invoices']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Issued:</span>
                <strong style="color: #1f6feb;"><?= number_format($invoiceStats['issued_invoices']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Paid:</span>
                <strong style="color: #10b981;"><?= number_format($invoiceStats['paid_invoices']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Voided:</span>
                <strong style="color: #ef4444;"><?= number_format($invoiceStats['voided_invoices']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                <span style="color: #6b7280;">Total Invoiced:</span>
                <strong style="color: #10b981;">$<?= number_format($invoiceStats['total_invoiced_usd'], 0) ?></strong>
            </div>
        </div>
    </div>

    <!-- Users -->
    <div class="card">
        <h2 style="margin: 0 0 15px; font-size: 1.2rem; color: #10b981;">Users & Team</h2>
        <div style="display: grid; gap: 10px;">
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Total Users:</span>
                <strong><?= number_format($userStats['total_users']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Active Users:</span>
                <strong style="color: #10b981;"><?= number_format($userStats['active_users']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Admins:</span>
                <strong><?= number_format($userStats['admin_count']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Sales Reps:</span>
                <strong><?= number_format($userStats['sales_rep_count']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Warehouse Staff:</span>
                <strong><?= number_format($userStats['warehouse_count']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                <span style="color: #6b7280;">Accountants:</span>
                <strong><?= number_format($userStats['accountant_count']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Warehouse -->
    <div class="card">
        <h2 style="margin: 0 0 15px; font-size: 1.2rem; color: #f59e0b;">Warehouse</h2>
        <div style="display: grid; gap: 10px;">
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Total Movements:</span>
                <strong><?= number_format($warehouseStats['total_movements']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Stock In:</span>
                <strong style="color: #10b981;"><?= number_format($warehouseStats['movements_in']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                <span style="color: #6b7280;">Stock Out:</span>
                <strong style="color: #ef4444;"><?= number_format($warehouseStats['movements_out']) ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                <span style="color: #6b7280;">Adjustments:</span>
                <strong style="color: #f59e0b;"><?= number_format($warehouseStats['adjustments']) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- Financial Performance -->
<div class="card">
    <h2 style="margin: 0 0 20px; font-size: 1.4rem;">Financial Performance</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
        <div style="padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Collection Rate</div>
            <div style="font-size: 2.5rem; font-weight: 700; color: <?= $collectionRate >= 80 ? '#10b981' : ($collectionRate >= 60 ? '#f59e0b' : '#ef4444') ?>;">
                <?= number_format($collectionRate, 1) ?>%
            </div>
            <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
                $<?= number_format($paymentStats['total_collected_usd'], 0) ?> / $<?= number_format($invoiceStats['total_invoiced_usd'], 0) ?>
            </div>
        </div>

        <div style="padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Average Order Value</div>
            <div style="font-size: 2.5rem; font-weight: 700; color: #1f6feb;">
                $<?= number_format($orderStats['avg_order_value_usd'], 0) ?>
            </div>
            <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
                Across <?= number_format($orderStats['total_orders']) ?> orders
            </div>
        </div>

        <div style="padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Revenue per Customer</div>
            <div style="font-size: 2.5rem; font-weight: 700; color: #8b5cf6;">
                $<?= $customerStats['total_customers'] > 0 ? number_format($paymentStats['total_collected_usd'] / $customerStats['total_customers'], 0) : 0 ?>
            </div>
            <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
                <?= number_format($customerStats['total_customers']) ?> customers
            </div>
        </div>

        <div style="padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center;">
            <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Orders per Customer</div>
            <div style="font-size: 2.5rem; font-weight: 700; color: #10b981;">
                <?= $customerStats['total_customers'] > 0 ? number_format($orderStats['total_orders'] / $customerStats['total_customers'], 1) : 0 ?>
            </div>
            <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
                Customer engagement metric
            </div>
        </div>
    </div>
</div>

<!-- Help Text -->
<div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; color: #6b7280; font-size: 0.9rem;">
    <strong>About Statistics:</strong>
    <ul style="margin: 8px 0 0 20px;">
        <li>All financial figures are in USD unless otherwise noted</li>
        <li>"Recent Activity" shows data from the last 30 days</li>
        <li>Collection Rate = Total Collected / Total Invoiced</li>
        <li>Statistics are calculated in real-time from the database</li>
    </ul>
</div>

<?php
admin_render_layout_end();
?>
