<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/SalesPortalDashboard.php';
require_once __DIR__ . '/../../includes/lang.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'لوحة التحكم';
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

// Arabic order status labels
$orderStatusLabels = [
    'on_hold' => 'قيد الانتظار',
    'approved' => 'مُوافق عليه',
    'preparing' => 'قيد التحضير',
    'ready' => 'جاهز للاستلام',
    'in_transit' => 'قيد التوصيل',
    'delivered' => 'تم التسليم',
    'cancelled' => 'ملغى',
    'returned' => 'مرتجع',
];

// Arabic invoice status labels
$invoiceStatusLabels = [
    'draft' => 'مسودة',
    'pending' => 'معلق',
    'issued' => 'صادرة',
    'paid' => 'مدفوعة',
    'voided' => 'ملغاة',
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
    'subtitle' => 'عرض مباشر لطلباتك، فواتيرك، التوصيلات، ومخزون السيارة.',
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

<!-- Quick Actions -->
<section style="margin-bottom: 24px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
        <a href="orders.php?action=new" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px 16px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px; text-decoration: none; color: white; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(16, 185, 129, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
            <span style="font-size: 1.8rem;">📝</span>
            <span>طلب جديد</span>
        </a>
        <a href="add_customer.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px 16px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: 12px; text-decoration: none; color: white; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
            <span style="font-size: 1.8rem;">👤</span>
            <span>زبون جديد</span>
        </a>
        <a href="invoices.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px 16px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 12px; text-decoration: none; color: white; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(245, 158, 11, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
            <span style="font-size: 1.8rem;">💵</span>
            <span>تسجيل دفعة</span>
        </a>
        <a href="van_stock.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px 16px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); border-radius: 12px; text-decoration: none; color: white; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(139, 92, 246, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
            <span style="font-size: 1.8rem;">📦</span>
            <span>مخزون السيارة</span>
        </a>
        <a href="customers.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px 16px; background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); border-radius: 12px; text-decoration: none; color: white; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(236, 72, 153, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
            <span style="font-size: 1.8rem;">📋</span>
            <span>قائمة الزبائن</span>
        </a>
        <a href="receivables.php" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px 16px; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border-radius: 12px; text-decoration: none; color: white; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(239, 68, 68, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
            <span style="font-size: 1.8rem;">💰</span>
            <span>التحصيلات</span>
        </a>
    </div>
</section>

<?php
// Get current month/year quota and performance
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$repId = (int)$user['id'];

$monthlyQuota = 0.0;
$monthlySales = 0.0;
$ytdQuota = 0.0;
$ytdSales = 0.0;

try {
    // Get this month's quota
    $quotaStmt = $pdo->prepare("
        SELECT quota_usd
        FROM sales_quotas
        WHERE sales_rep_id = :rep_id AND year = :year AND month = :month
    ");
    $quotaStmt->execute([':rep_id' => $repId, ':year' => $currentYear, ':month' => $currentMonth]);
    $monthlyQuota = (float)($quotaStmt->fetchColumn() ?: 0);

    // Get this month's actual sales (delivered orders only)
    $salesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(o.total_usd), 0) as monthly_sales
        FROM orders o
        INNER JOIN customers c ON c.id = o.customer_id
        WHERE c.assigned_sales_rep_id = :rep_id
          AND o.status = 'delivered'
          AND YEAR(o.created_at) = :year
          AND MONTH(o.created_at) = :month
    ");
    $salesStmt->execute([':rep_id' => $repId, ':year' => $currentYear, ':month' => $currentMonth]);
    $monthlySales = (float)($salesStmt->fetchColumn() ?: 0);

    // Calculate YTD
    $ytdStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(sq.quota_usd), 0) as ytd_quota,
            COALESCE(SUM(o.total_usd), 0) as ytd_sales
        FROM sales_quotas sq
        LEFT JOIN customers c ON c.assigned_sales_rep_id = sq.sales_rep_id
        LEFT JOIN orders o ON o.customer_id = c.id
            AND o.status = 'delivered'
            AND YEAR(o.created_at) = sq.year
            AND MONTH(o.created_at) = sq.month
        WHERE sq.sales_rep_id = :rep_id
          AND sq.year = :year
    ");
    $ytdStmt->execute([':rep_id' => $repId, ':year' => $currentYear]);
    $ytdData = $ytdStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $ytdQuota = (float)($ytdData['ytd_quota'] ?? 0);
    $ytdSales = (float)($ytdData['ytd_sales'] ?? 0);
} catch (PDOException $e) {
    $errors[] = 'Quota/Performance: ' . $e->getMessage();
}

$quotaPercent = $monthlyQuota > 0 ? ($monthlySales / $monthlyQuota) * 100 : 0;
$ytdPercent = $ytdQuota > 0 ? ($ytdSales / $ytdQuota) * 100 : 0;

$overdueInvoices = [];
$arSummary = ['overdue_count' => 0, 'overdue_usd' => 0, 'critical_usd' => 0];

try {
    // Get overdue invoices
    $overdueStmt = $pdo->prepare("
        SELECT
            i.invoice_number,
            c.id as customer_id,
            c.name as customer_name,
            i.issued_at,
            i.due_date,
            DATEDIFF(NOW(), COALESCE(i.due_date, i.issued_at)) as days_overdue,
            (i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd,
            (i.total_lbp - COALESCE(pay.paid_lbp, 0)) as balance_lbp
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN customers c ON c.id = o.customer_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
            FROM payments
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = i.id
        WHERE c.assigned_sales_rep_id = :rep_id
          AND i.status != 'paid'
          AND i.status != 'voided'
          AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(pay.paid_lbp, 0) > 0.01)
          AND DATEDIFF(NOW(), COALESCE(i.due_date, i.issued_at)) > 30
        ORDER BY days_overdue DESC
        LIMIT 10
    ");
    $overdueStmt->execute([':rep_id' => $repId]);
    $overdueInvoices = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get critical AR summary
    $arSummaryStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT i.id) as overdue_count,
            SUM(i.total_usd - COALESCE(pay.paid_usd, 0)) as overdue_usd,
            SUM(CASE WHEN DATEDIFF(NOW(), COALESCE(i.due_date, i.issued_at)) > 90 THEN (i.total_usd - COALESCE(pay.paid_usd, 0)) ELSE 0 END) as critical_usd
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN customers c ON c.id = o.customer_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd
            FROM payments
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = i.id
        WHERE c.assigned_sales_rep_id = :rep_id
          AND i.status != 'paid'
          AND i.status != 'voided'
          AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01)
          AND DATEDIFF(NOW(), COALESCE(i.due_date, i.issued_at)) > 30
    ");
    $arSummaryStmt->execute([':rep_id' => $repId]);
    $arSummary = $arSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: $arSummary;
} catch (PDOException $e) {
    $errors[] = 'Receivables: ' . $e->getMessage();
}
?>

<!-- Overdue Payment Alerts -->
<?php if (!empty($overdueInvoices)): ?>
<section style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border-radius: 16px; padding: 24px; margin-bottom: 24px; color: white;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0 0 8px 0; font-size: 1.4rem; font-weight: 700; color: white;">🚨 تنبيه الدفعات المتأخرة</h2>
            <p style="margin: 0; opacity: 0.9; font-size: 0.95rem;">هذه الفواتير تتطلب اهتماماً فورياً</p>
        </div>
        <a href="receivables.php" style="background: rgba(255,255,255,0.2); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.3);">
            عرض لوحة التحصيلات ←
        </a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 16px; backdrop-filter: blur(10px);">
            <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 4px;">الفواتير المتأخرة</div>
            <div style="font-size: 2rem; font-weight: 700;"><?= number_format((int)$arSummary['overdue_count']) ?></div>
        </div>
        <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 16px; backdrop-filter: blur(10px);">
            <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 4px;">إجمالي المتأخر</div>
            <div style="font-size: 2rem; font-weight: 700;">$<?= number_format((float)$arSummary['overdue_usd'], 0) ?></div>
        </div>
        <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 16px; backdrop-filter: blur(10px);">
            <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 4px;">حرج (90+ يوم)</div>
            <div style="font-size: 2rem; font-weight: 700;">$<?= number_format((float)$arSummary['critical_usd'], 0) ?></div>
        </div>
    </div>

    <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 16px; max-height: 300px; overflow-y: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid rgba(255,255,255,0.3);">
                    <th style="text-align: right; padding: 8px; font-size: 0.85rem; opacity: 0.9;">الفاتورة</th>
                    <th style="text-align: right; padding: 8px; font-size: 0.85rem; opacity: 0.9;">الزبون</th>
                    <th style="text-align: right; padding: 8px; font-size: 0.85rem; opacity: 0.9;">أيام التأخير</th>
                    <th style="text-align: right; padding: 8px; font-size: 0.85rem; opacity: 0.9;">المبلغ</th>
                    <th style="text-align: center; padding: 8px; font-size: 0.85rem; opacity: 0.9;">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overdueInvoices as $inv): ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <td style="padding: 10px; font-weight: 600;"><?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding: 10px;"><?= htmlspecialchars($inv['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding: 10px; text-align: right;">
                        <span style="background: <?= $inv['days_overdue'] > 90 ? '#7f1d1d' : ($inv['days_overdue'] > 60 ? '#991b1b' : '#b91c1c') ?>; padding: 4px 10px; border-radius: 6px; font-weight: 600;">
                            <?= (int)$inv['days_overdue'] ?> يوم
                        </span>
                    </td>
                    <td style="padding: 10px; text-align: right; font-weight: 600; font-size: 1.05rem;">
                        $<?= number_format((float)$inv['balance_usd'], 2) ?>
                    </td>
                    <td style="padding: 10px; text-align: center;">
                        <a href="invoices.php" style="background: rgba(255,255,255,0.9); color: #dc2626; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600;">
                            تسجيل دفعة
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Sales Quota Performance -->
<?php if ($monthlyQuota > 0 || $ytdQuota > 0): ?>
<section style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; padding: 24px; margin-bottom: 32px; color: white;">
    <h2 style="margin: 0 0 20px 0; font-size: 1.4rem; font-weight: 700; color: white;">🎯 أداء حصة المبيعات</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
        <!-- This Month -->
        <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 20px; backdrop-filter: blur(10px);">
            <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 8px;">هذا الشهر</div>
            <div style="font-size: 2rem; font-weight: 700; margin-bottom: 8px;">$<?= number_format($monthlySales, 0) ?></div>
            <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 12px;">من $<?= number_format($monthlyQuota, 0) ?> الحصة</div>
            <div style="background: rgba(255,255,255,0.2); border-radius: 8px; height: 12px; overflow: hidden;">
                <div style="background: <?= $quotaPercent >= 100 ? '#10b981' : ($quotaPercent >= 75 ? '#f59e0b' : '#ef4444') ?>; height: 100%; width: <?= min(100, $quotaPercent) ?>%; transition: width 0.3s;"></div>
            </div>
            <div style="margin-top: 8px; font-size: 1.2rem; font-weight: 600;">
                <?= number_format($quotaPercent, 1) ?>%
                <?php if ($quotaPercent >= 100): ?>
                    <span style="color: #10b981;">✓ تم تحقيقها!</span>
                <?php elseif ($quotaPercent >= 75): ?>
                    <span style="color: #fbbf24;">▲ على المسار</span>
                <?php else: ?>
                    <span style="color: #fca5a5;">⚠ يحتاج اهتمام</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Year to Date -->
        <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 20px; backdrop-filter: blur(10px);">
            <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 8px;">منذ بداية السنة</div>
            <div style="font-size: 2rem; font-weight: 700; margin-bottom: 8px;">$<?= number_format($ytdSales, 0) ?></div>
            <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 12px;">من $<?= number_format($ytdQuota, 0) ?> الحصة</div>
            <div style="background: rgba(255,255,255,0.2); border-radius: 8px; height: 12px; overflow: hidden;">
                <div style="background: <?= $ytdPercent >= 100 ? '#10b981' : ($ytdPercent >= 75 ? '#f59e0b' : '#ef4444') ?>; height: 100%; width: <?= min(100, $ytdPercent) ?>%; transition: width 0.3s;"></div>
            </div>
            <div style="margin-top: 8px; font-size: 1.2rem; font-weight: 600;">
                <?= number_format($ytdPercent, 1) ?>%
                <?php if ($ytdPercent >= 100): ?>
                    <span style="color: #10b981;">✓ تجاوزت!</span>
                <?php elseif ($ytdPercent >= 90): ?>
                    <span style="color: #fbbf24;">▲ قوي</span>
                <?php else: ?>
                    <span style="color: #fca5a5;">⚠ متأخر</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Gap to Quota -->
        <?php $gapAmount = $monthlyQuota - $monthlySales; ?>
        <div style="background: rgba(255,255,255,0.15); border-radius: 12px; padding: 20px; backdrop-filter: blur(10px);">
            <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 8px;">
                <?= $gapAmount > 0 ? 'الفجوة للحصة' : 'الفائض' ?>
            </div>
            <div style="font-size: 2rem; font-weight: 700; margin-bottom: 8px; color: <?= $gapAmount > 0 ? '#fca5a5' : '#86efac' ?>;">
                $<?= number_format(abs($gapAmount), 0) ?>
            </div>
            <div style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 12px;">
                <?php
                $daysLeft = (int)date('t') - (int)date('j');
                echo $daysLeft . ' يوم متبقي في الشهر';
                ?>
            </div>
            <?php if ($gapAmount > 0 && $daysLeft > 0): ?>
                <div style="font-size: 0.85rem; background: rgba(255,255,255,0.2); padding: 10px; border-radius: 6px; margin-top: 8px;">
                    <strong>الهدف اليومي:</strong> $<?= number_format($gapAmount / $daysLeft, 0) ?>/يوم
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="metrics-grid">
    <article class="metric-card">
        <span class="label">طلبات اليوم</span>
        <strong><?= number_format($metrics['orders_today'] ?? 0) ?></strong>
        <small>منذ منتصف الليل</small>
    </article>
    <article class="metric-card">
        <span class="label">الطلبات المفتوحة</span>
        <strong><?= number_format($metrics['open_orders'] ?? 0) ?></strong>
        <small>لم يتم تسليمها بعد</small>
    </article>
    <article class="metric-card">
        <span class="label">بانتظار الموافقة</span>
        <strong><?= number_format($metrics['awaiting_approval'] ?? 0) ?></strong>
        <small>قيد الانتظار</small>
    </article>
    <article class="metric-card">
        <span class="label">قيد التوصيل</span>
        <strong><?= number_format($metrics['in_transit'] ?? 0) ?></strong>
        <small>الطلبات في الطريق</small>
    </article>
    <article class="metric-card">
        <span class="label">توصيلات اليوم</span>
        <strong><?= number_format($deliveriesToday) ?></strong>
        <small>مجدولة لليوم</small>
    </article>
    <article class="metric-card">
        <span class="label">المستحقات المفتوحة</span>
        <strong>$<?= number_format($invoiceTotals['usd'], 2) ?></strong>
        <small><?= number_format($invoiceTotals['lbp']) ?> ل.ل.</small>
    </article>
</section>

<section class="data-grid">
    <article class="data-card">
        <header>
            <h2>الطلبات الأخيرة</h2>
            <span class="badge badge-info">الأحدث</span>
        </header>
        <?php if (!$latestOrders): ?>
            <div class="empty-state">لا توجد طلبات.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>الطلب</th>
                        <th>الحالة</th>
                        <th>المجموع ($)</th>
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
                                <small><?= htmlspecialchars($order['customer_name'] ?? 'غير معين', ENT_QUOTES, 'UTF-8') ?></small>
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
            <h2>الفواتير المعلقة</h2>
            <span class="badge badge-warning">المستحقات</span>
        </header>
        <?php if (!$pendingInvoices): ?>
            <div class="empty-state">لا توجد فواتير صادرة بعد.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>الفاتورة</th>
                        <th>الحالة</th>
                        <th>الرصيد</th>
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
                                <small><?= number_format(max(0.0, (float)$invoice['total_lbp'] - (float)$invoice['paid_lbp'])) ?> ل.ل.</small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="data-card">
        <header>
            <h2>التوصيلات القادمة</h2>
            <span class="badge badge-info">اللوجستيات</span>
        </header>
        <?php if (!$upcomingDeliveries): ?>
            <div class="empty-state">لا توجد توصيلات قادمة مجدولة.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>الزبون</th>
                        <th>الموعد</th>
                        <th>الحالة</th>
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
                            <td><?= htmlspecialchars($orderStatusLabels[$delivery['status'] ?? 'pending'] ?? ucwords(str_replace('_', ' ', (string)($delivery['status'] ?? 'pending'))), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="data-card">
        <header>
            <h2>الدفعات الأخيرة</h2>
            <span class="badge badge-success">التحصيلات</span>
        </header>
        <?php if (!$recentPayments): ?>
            <div class="empty-state">لا توجد دفعات مسجلة بعد.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>الفاتورة</th>
                        <th>المبلغ</th>
                        <th>الاستلام</th>
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
                                    <small><?= number_format((float)$payment['amount_lbp']) ?> ل.ل.</small>
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
        <h2>ملخص مخزون السيارة</h2>
        <span class="badge badge-neutral">المخزون</span>
    </header>
    <div class="van-stock-summary">
        <div>
            <span class="label">عدد المنتجات</span>
            <strong><?= number_format((int)$vanStockSummary['sku_count']) ?></strong>
        </div>
        <div>
            <span class="label">الكمية المتوفرة</span>
            <strong><?= number_format((float)$vanStockSummary['total_units'], 1) ?></strong>
        </div>
        <div>
            <span class="label">قيمة المخزون ($)</span>
            <strong>$<?= number_format((float)$vanStockSummary['total_value_usd'], 2) ?></strong>
        </div>
    </div>

    <h3>آخر الحركات</h3>
    <?php if (!$vanStockMovements): ?>
        <div class="empty-state">لا توجد حركات مخزون مسجلة.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>المنتج</th>
                    <th>التغيير</th>
                    <th>السبب</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vanStockMovements as $movement): ?>
                    <?php
                    $delta = (float)($movement['delta_qty'] ?? 0);
                    $deltaLabel = ($delta > 0 ? '+' : '') . number_format($delta, 1);
                    $movementAt = $movement['created_at'] ?? null;
                    $movementLabel = $movementAt ? date('M j, H:i', strtotime($movementAt)) : '—';
                    // Translate common reasons
                    $reasonTranslations = [
                        'sale' => 'بيع',
                        'return' => 'إرجاع',
                        'transfer' => 'نقل',
                        'adjustment' => 'تعديل',
                        'loading' => 'تحميل',
                    ];
                    $reason = $movement['reason'] ?? '—';
                    $reasonLabel = $reasonTranslations[$reason] ?? $reason;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($movementLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= htmlspecialchars($movement['item_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?><br>
                            <small><?= htmlspecialchars($movement['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                        </td>
                        <td><?= $deltaLabel ?></td>
                        <td><?= htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php sales_portal_render_layout_end(); ?>
