<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Collections & Receivables';
$repId = (int)$user['id'];

// Handle follow-up creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_followup') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid CSRF token');
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $dueDate = $_POST['due_date'] ?? null;

        // Verify customer belongs to rep
        $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id");
        $checkStmt->execute([':id' => $customerId, ':rep_id' => $repId]);

        if ($checkStmt->fetch() && $note !== '') {
            $followupStmt = $pdo->prepare("
                INSERT INTO ar_followups (customer_id, note, due_date, created_by_user_id, assigned_to_user_id, created_at)
                VALUES (:customer_id, :note, :due_date, :created_by, :assigned_to, NOW())
            ");
            $followupStmt->execute([
                ':customer_id' => $customerId,
                ':note' => $note,
                ':due_date' => $dueDate ?: null,
                ':created_by' => $repId,
                ':assigned_to' => $repId
            ]);
            flash('success', 'Follow-up note added successfully');
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . ($customerId ? '?customer_id=' . $customerId : ''));
    exit;
}

$customerFilter = (int)($_GET['customer_id'] ?? 0);
$priorityFilter = $_GET['priority'] ?? 'all';

// Get comprehensive aging analysis with additional metrics
$agingQuery = "
    SELECT
        SUM(CASE WHEN days_old BETWEEN 0 AND 30 THEN balance_usd ELSE 0 END) AS bucket_0_30_usd,
        SUM(CASE WHEN days_old BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS bucket_0_30_count,

        SUM(CASE WHEN days_old BETWEEN 31 AND 60 THEN balance_usd ELSE 0 END) AS bucket_31_60_usd,
        SUM(CASE WHEN days_old BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS bucket_31_60_count,

        SUM(CASE WHEN days_old BETWEEN 61 AND 90 THEN balance_usd ELSE 0 END) AS bucket_61_90_usd,
        SUM(CASE WHEN days_old BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS bucket_61_90_count,

        SUM(CASE WHEN days_old > 90 THEN balance_usd ELSE 0 END) AS bucket_90_plus_usd,
        SUM(CASE WHEN days_old > 90 THEN 1 ELSE 0 END) AS bucket_90_plus_count,

        SUM(balance_usd) as total_outstanding,
        COUNT(DISTINCT customer_id) as customers_with_balance,
        AVG(days_old) as avg_days_outstanding
    FROM (
        SELECT
            o.customer_id,
            i.id,
            DATEDIFF(NOW(), i.issued_at) as days_old,
            (i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN customers c ON c.id = o.customer_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd
            FROM payments
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = i.id
        WHERE c.assigned_sales_rep_id = :rep_id
          AND i.status IN ('issued', 'paid')
          AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01)
    ) aging_data
";
$agingStmt = $pdo->prepare($agingQuery);
$agingStmt->execute([':rep_id' => $repId]);
$aging = $agingStmt->fetch(PDO::FETCH_ASSOC);

// Calculate collection efficiency metrics
$totalOutstanding = (float)$aging['total_outstanding'];
$criticalAmount = (float)$aging['bucket_90_plus_usd'];
$collectionEfficiency = $totalOutstanding > 0 ? (($totalOutstanding - $criticalAmount) / $totalOutstanding) * 100 : 100;

// Get customers with outstanding balances - prioritized
$customersQuery = "
    SELECT
        c.id,
        c.name,
        c.phone,
        c.location,
        c.customer_tier,
        SUM(i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd,
        COUNT(DISTINCT i.id) as invoice_count,
        MIN(DATEDIFF(NOW(), i.issued_at)) as oldest_invoice_days,
        MAX(DATEDIFF(NOW(), i.issued_at)) as days_overdue,
        MAX(p.received_at) as last_payment_date,
        CASE
            WHEN MAX(DATEDIFF(NOW(), i.issued_at)) > 90 THEN 'critical'
            WHEN MAX(DATEDIFF(NOW(), i.issued_at)) > 60 THEN 'high'
            WHEN MAX(DATEDIFF(NOW(), i.issued_at)) > 30 THEN 'medium'
            ELSE 'low'
        END as priority
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    INNER JOIN invoices i ON i.order_id = o.id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd
        FROM payments
        GROUP BY invoice_id
    ) pay ON pay.invoice_id = i.id
    LEFT JOIN payments p ON p.invoice_id = i.id
    WHERE c.assigned_sales_rep_id = :rep_id
      AND i.status IN ('issued', 'paid')
      AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01)
    GROUP BY c.id, c.name, c.phone, c.location, c.customer_tier
    HAVING balance_usd > 0.01
    ORDER BY
        CASE
            WHEN MAX(DATEDIFF(NOW(), i.issued_at)) > 90 THEN 1
            WHEN MAX(DATEDIFF(NOW(), i.issued_at)) > 60 THEN 2
            WHEN MAX(DATEDIFF(NOW(), i.issued_at)) > 30 THEN 3
            ELSE 4
        END,
        balance_usd DESC
";
$customersStmt = $pdo->prepare($customersQuery);
$customersStmt->execute([':rep_id' => $repId]);
$allCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter by priority if needed
if ($priorityFilter !== 'all') {
    $customers = array_filter($allCustomers, fn($c) => $c['priority'] === $priorityFilter);
} else {
    $customers = $allCustomers;
}

// Calculate priority counts
$priorityCounts = [
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0
];
foreach ($allCustomers as $customer) {
    $priorityCounts[$customer['priority']]++;
}

// Get payment behavior trends (last 90 days)
$paymentTrendsStmt = $pdo->prepare("
    SELECT
        DATE(p.received_at) as payment_date,
        SUM(p.amount_usd) as daily_collected
    FROM payments p
    INNER JOIN invoices i ON i.id = p.invoice_id
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE c.assigned_sales_rep_id = :rep_id
      AND p.received_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY DATE(p.received_at)
    ORDER BY payment_date ASC
");
$paymentTrendsStmt->execute([':rep_id' => $repId]);
$paymentTrends = $paymentTrendsStmt->fetchAll(PDO::FETCH_ASSOC);

// If customer selected, get detailed information
$selectedCustomer = null;
$customerInvoices = [];
$followups = [];
$paymentHistory = [];

if ($customerFilter > 0) {
    $selectedStmt = $pdo->prepare("
        SELECT c.*,
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(i.total_usd), 0) as lifetime_revenue
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id
        LEFT JOIN invoices i ON i.order_id = o.id AND i.status IN ('issued', 'paid')
        WHERE c.id = :id AND c.assigned_sales_rep_id = :rep_id
        GROUP BY c.id
    ");
    $selectedStmt->execute([':id' => $customerFilter, ':rep_id' => $repId]);
    $selectedCustomer = $selectedStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCustomer) {
        // Get outstanding invoices
        $invoicesQuery = "
            SELECT
                i.id,
                i.invoice_number,
                i.issued_at,
                i.total_usd,
                COALESCE(pay.paid_usd, 0) as paid_usd,
                (i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd,
                DATEDIFF(NOW(), i.issued_at) as days_old
            FROM invoices i
            INNER JOIN orders o ON o.id = i.order_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount_usd) as paid_usd
                FROM payments
                GROUP BY invoice_id
            ) pay ON pay.invoice_id = i.id
            WHERE o.customer_id = :customer_id
              AND i.status IN ('issued', 'paid')
              AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01)
            ORDER BY i.issued_at ASC
        ";
        $invoicesStmt = $pdo->prepare($invoicesQuery);
        $invoicesStmt->execute([':customer_id' => $customerFilter]);
        $customerInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get payment history
        $paymentHistoryStmt = $pdo->prepare("
            SELECT
                p.id,
                p.amount_usd,
                p.payment_method,
                p.received_at,
                p.notes,
                i.invoice_number
            FROM payments p
            INNER JOIN invoices i ON i.id = p.invoice_id
            INNER JOIN orders o ON o.id = i.order_id
            WHERE o.customer_id = :customer_id
            ORDER BY p.received_at DESC
            LIMIT 20
        ");
        $paymentHistoryStmt->execute([':customer_id' => $customerFilter]);
        $paymentHistory = $paymentHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get follow-ups
        $followupsStmt = $pdo->prepare("
            SELECT f.*, u.name as created_by_name
            FROM ar_followups f
            LEFT JOIN users u ON u.id = f.created_by_user_id
            WHERE f.customer_id = :customer_id
            ORDER BY f.created_at DESC
            LIMIT 15
        ");
        $followupsStmt->execute([':customer_id' => $customerFilter]);
        $followups = $followupsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Prepare chart data
$agingLabels = ['0-30 Days', '31-60 Days', '61-90 Days', '90+ Days'];
$agingValues = [
    (float)$aging['bucket_0_30_usd'],
    (float)$aging['bucket_31_60_usd'],
    (float)$aging['bucket_61_90_usd'],
    (float)$aging['bucket_90_plus_usd']
];

$trendLabels = array_map(fn($row) => date('M d', strtotime($row['payment_date'])), $paymentTrends);
$trendValues = array_map(fn($row) => (float)$row['daily_collected'], $paymentTrends);

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => '💰 Collections & Receivables',
    'subtitle' => 'Manage outstanding payments and track collection activities',
    'user' => $user,
    'active' => 'receivables',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>'
]);
?>

<style>
:root {
    --color-critical: #dc2626;
    --color-high: #ea580c;
    --color-medium: #f59e0b;
    --color-low: #10b981;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.metric-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    position: relative;
    overflow: hidden;
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

.metric-card.critical { --card-color: var(--color-critical); }
.metric-card.high { --card-color: var(--color-high); }
.metric-card.medium { --card-color: var(--color-medium); }
.metric-card.low { --card-color: var(--color-low); }

.metric-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
}

.metric-value {
    font-size: 2rem;
    font-weight: 800;
    color: #111827;
    margin-bottom: 4px;
}

.metric-sub {
    font-size: 0.875rem;
    color: #9ca3af;
}

.aging-buckets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.aging-bucket {
    background: white;
    border-radius: 12px;
    padding: 20px;
    border: 2px solid;
    transition: transform 0.2s, box-shadow 0.2s;
}

.aging-bucket:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.aging-bucket.current { border-color: var(--color-low); }
.aging-bucket.warning { border-color: var(--color-medium); }
.aging-bucket.danger { border-color: var(--color-high); }
.aging-bucket.critical { border-color: var(--color-critical); }

.aging-bucket-label {
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.aging-bucket.current .aging-bucket-label { color: var(--color-low); }
.aging-bucket.warning .aging-bucket-label { color: var(--color-medium); }
.aging-bucket.danger .aging-bucket-label { color: var(--color-high); }
.aging-bucket.critical .aging-bucket-label { color: var(--color-critical); }

.aging-bucket-value {
    font-size: 1.75rem;
    font-weight: 800;
    color: #111827;
    margin-bottom: 4px;
}

.aging-bucket-count {
    font-size: 0.875rem;
    color: #6b7280;
}

.priority-filters {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.priority-filter {
    padding: 8px 16px;
    border-radius: 8px;
    border: 2px solid;
    background: white;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.priority-filter:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.priority-filter.critical { border-color: var(--color-critical); color: var(--color-critical); }
.priority-filter.critical.active { background: var(--color-critical); color: white; }
.priority-filter.high { border-color: var(--color-high); color: var(--color-high); }
.priority-filter.high.active { background: var(--color-high); color: white; }
.priority-filter.medium { border-color: var(--color-medium); color: var(--color-medium); }
.priority-filter.medium.active { background: var(--color-medium); color: white; }
.priority-filter.low { border-color: var(--color-low); color: var(--color-low); }
.priority-filter.low.active { background: var(--color-low); color: white; }
.priority-filter.all { border-color: #6b7280; color: #6b7280; }
.priority-filter.all.active { background: #6b7280; color: white; }

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

.customers-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    margin-bottom: 32px;
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

.text-center {
    text-align: center;
}

.priority-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.priority-badge.critical { background: rgba(220, 38, 38, 0.1); color: var(--color-critical); }
.priority-badge.high { background: rgba(234, 88, 12, 0.1); color: var(--color-high); }
.priority-badge.medium { background: rgba(245, 158, 11, 0.1); color: var(--color-medium); }
.priority-badge.low { background: rgba(16, 185, 129, 0.1); color: var(--color-low); }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid #e5e7eb;
    background: white;
    color: #111827;
    cursor: pointer;
    transition: all 0.2s;
}

.btn:hover {
    background: #f3f4f6;
}

.btn-primary {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
}

.btn-primary:hover {
    background: var(--accent-2);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.875rem;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}

.alert {
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 16px;
    border-left: 4px solid;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-left-color: var(--color-low);
    color: #065f46;
}

.alert-error {
    background: rgba(220, 38, 38, 0.1);
    border-left-color: var(--color-critical);
    color: #991b1b;
}

.empty-state {
    text-align: center;
    padding: 60px 24px;
    color: #9ca3af;
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 16px;
}

.section-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    margin-bottom: 24px;
}

.section-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 16px;
    color: #111827;
}

.timeline {
    position: relative;
    padding-left: 32px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e5e7eb;
}

.timeline-item {
    position: relative;
    padding-bottom: 24px;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -28px;
    top: 4px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--accent);
    border: 3px solid white;
    box-shadow: 0 0 0 2px var(--accent);
}

.timeline-date {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 4px;
}

.timeline-content {
    font-size: 0.95rem;
    color: #111827;
}

@media (max-width: 768px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Flash Messages -->
<?php
$flashes = consume_flashes();
foreach ($flashes as $msg):
?>
<div class="alert alert-<?= $msg['type'] ?>">
    <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endforeach; ?>

<!-- Key Metrics -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-label">Total Outstanding</div>
        <div class="metric-value">$<?= number_format($totalOutstanding, 2) ?></div>
        <div class="metric-sub"><?= (int)$aging['customers_with_balance'] ?> customers</div>
    </div>

    <div class="metric-card critical">
        <div class="metric-label">Critical (90+ Days)</div>
        <div class="metric-value">$<?= number_format((float)$aging['bucket_90_plus_usd'], 2) ?></div>
        <div class="metric-sub"><?= (int)$aging['bucket_90_plus_count'] ?> invoices</div>
    </div>

    <div class="metric-card medium">
        <div class="metric-label">Avg Days Outstanding</div>
        <div class="metric-value"><?= number_format((float)$aging['avg_days_outstanding'], 0) ?></div>
        <div class="metric-sub">days</div>
    </div>

    <div class="metric-card low">
        <div class="metric-label">Collection Health</div>
        <div class="metric-value"><?= number_format($collectionEfficiency, 1) ?>%</div>
        <div class="metric-sub">efficiency score</div>
    </div>
</div>

<!-- Aging Buckets -->
<h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 16px;">📊 Aging Analysis</h2>
<div class="aging-buckets">
    <div class="aging-bucket current">
        <div class="aging-bucket-label">0-30 Days (Current)</div>
        <div class="aging-bucket-value">$<?= number_format((float)$aging['bucket_0_30_usd'], 2) ?></div>
        <div class="aging-bucket-count"><?= (int)$aging['bucket_0_30_count'] ?> invoices</div>
    </div>

    <div class="aging-bucket warning">
        <div class="aging-bucket-label">31-60 Days</div>
        <div class="aging-bucket-value">$<?= number_format((float)$aging['bucket_31_60_usd'], 2) ?></div>
        <div class="aging-bucket-count"><?= (int)$aging['bucket_31_60_count'] ?> invoices</div>
    </div>

    <div class="aging-bucket danger">
        <div class="aging-bucket-label">61-90 Days</div>
        <div class="aging-bucket-value">$<?= number_format((float)$aging['bucket_61_90_usd'], 2) ?></div>
        <div class="aging-bucket-count"><?= (int)$aging['bucket_61_90_count'] ?> invoices</div>
    </div>

    <div class="aging-bucket critical">
        <div class="aging-bucket-label">90+ Days (Critical)</div>
        <div class="aging-bucket-value">$<?= number_format((float)$aging['bucket_90_plus_usd'], 2) ?></div>
        <div class="aging-bucket-count"><?= (int)$aging['bucket_90_plus_count'] ?> invoices</div>
    </div>
</div>

<!-- Charts -->
<div class="charts-grid">
    <div class="chart-card">
        <h3>AR Aging Distribution</h3>
        <div class="chart-container">
            <canvas id="agingChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>Collection Trends (Last 90 Days)</h3>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
</div>

<?php if ($selectedCustomer): ?>
<!-- Customer Detail View -->
<div style="margin-bottom: 24px;">
    <a href="?" class="btn" style="margin-bottom: 16px;">← Back to All Customers</a>

    <div class="section-card">
        <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 8px;">
            <?= htmlspecialchars($selectedCustomer['name'], ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 16px;">
            <div>
                <div style="font-size: 0.875rem; color: #6b7280;">Phone</div>
                <div style="font-weight: 600;"><?= htmlspecialchars($selectedCustomer['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div>
                <div style="font-size: 0.875rem; color: #6b7280;">Location</div>
                <div style="font-weight: 600;"><?= htmlspecialchars($selectedCustomer['location'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div>
                <div style="font-size: 0.875rem; color: #6b7280;">Total Orders</div>
                <div style="font-weight: 600;"><?= number_format((int)$selectedCustomer['total_orders']) ?></div>
            </div>
            <div>
                <div style="font-size: 0.875rem; color: #6b7280;">Lifetime Revenue</div>
                <div style="font-weight: 600;">$<?= number_format((float)$selectedCustomer['lifetime_revenue'], 2) ?></div>
            </div>
        </div>
    </div>

    <!-- Outstanding Invoices -->
    <div class="section-card">
        <h3 class="section-title">Outstanding Invoices</h3>
        <?php if (!empty($customerInvoices)): ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Issued Date</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Balance</th>
                        <th class="text-center">Days Old</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customerInvoices as $inv):
                        $daysOld = (int)$inv['days_old'];
                        if ($daysOld <= 30) {
                            $daysColor = 'var(--color-low)';
                        } elseif ($daysOld <= 60) {
                            $daysColor = 'var(--color-medium)';
                        } elseif ($daysOld <= 90) {
                            $daysColor = 'var(--color-high)';
                        } else {
                            $daysColor = 'var(--color-critical)';
                        }
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= date('M d, Y', strtotime($inv['issued_at'])) ?></td>
                        <td class="text-right">$<?= number_format((float)$inv['total_usd'], 2) ?></td>
                        <td class="text-right">$<?= number_format((float)$inv['paid_usd'], 2) ?></td>
                        <td class="text-right"><strong style="color: var(--color-high);">$<?= number_format((float)$inv['balance_usd'], 2) ?></strong></td>
                        <td class="text-center">
                            <span style="color: <?= $daysColor ?>; font-weight: 600;"><?= $daysOld ?> days</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p style="color: #6b7280;">No outstanding invoices</p>
        <?php endif; ?>
    </div>

    <!-- Payment History -->
    <?php if (!empty($paymentHistory)): ?>
    <div class="section-card">
        <h3 class="section-title">💳 Payment History</h3>
        <div class="timeline">
            <?php foreach ($paymentHistory as $payment): ?>
            <div class="timeline-item">
                <div class="timeline-date">
                    <?= date('M d, Y g:i A', strtotime($payment['received_at'])) ?>
                </div>
                <div class="timeline-content">
                    <strong style="color: var(--color-low);">$<?= number_format((float)$payment['amount_usd'], 2) ?></strong>
                    received for invoice <strong><?= htmlspecialchars($payment['invoice_number'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if ($payment['payment_method']): ?>
                        via <?= htmlspecialchars($payment['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if ($payment['notes']): ?>
                        <div style="font-size: 0.875rem; color: #6b7280; margin-top: 4px;">
                            <?= htmlspecialchars($payment['notes'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Add Follow-up -->
    <div class="section-card">
        <h3 class="section-title">➕ Add Follow-up Note</h3>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_followup">
            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem;">Note</label>
                <textarea name="note" rows="3" class="form-control" placeholder="Record your follow-up activity..." required></textarea>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem;">Due Date (Optional)</label>
                <input type="date" name="due_date" class="form-control">
            </div>

            <button type="submit" class="btn btn-primary">Add Follow-up</button>
        </form>
    </div>

    <!-- Follow-up History -->
    <?php if (!empty($followups)): ?>
    <div class="section-card">
        <h3 class="section-title">📝 Follow-up History</h3>
        <div class="timeline">
            <?php foreach ($followups as $followup): ?>
            <div class="timeline-item">
                <div class="timeline-date">
                    <?= date('M d, Y g:i A', strtotime($followup['created_at'])) ?>
                    by <?= htmlspecialchars($followup['created_by_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($followup['due_date']): ?>
                        | Due: <?= date('M d, Y', strtotime($followup['due_date'])) ?>
                    <?php endif; ?>
                </div>
                <div class="timeline-content">
                    <?= nl2br(htmlspecialchars($followup['note'], ENT_QUOTES, 'UTF-8')) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Collection Priority Queue -->
<h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 16px;">🎯 Collection Priority Queue</h2>

<!-- Priority Filters -->
<div class="priority-filters">
    <a href="?" class="priority-filter all <?= $priorityFilter === 'all' ? 'active' : '' ?>">
        All (<?= array_sum($priorityCounts) ?>)
    </a>
    <a href="?priority=critical" class="priority-filter critical <?= $priorityFilter === 'critical' ? 'active' : '' ?>">
        🔴 Critical (<?= $priorityCounts['critical'] ?>)
    </a>
    <a href="?priority=high" class="priority-filter high <?= $priorityFilter === 'high' ? 'active' : '' ?>">
        🟠 High (<?= $priorityCounts['high'] ?>)
    </a>
    <a href="?priority=medium" class="priority-filter medium <?= $priorityFilter === 'medium' ? 'active' : '' ?>">
        🟡 Medium (<?= $priorityCounts['medium'] ?>)
    </a>
    <a href="?priority=low" class="priority-filter low <?= $priorityFilter === 'low' ? 'active' : '' ?>">
        🟢 Low (<?= $priorityCounts['low'] ?>)
    </a>
</div>

<!-- Customers List -->
<?php if (!empty($customers)): ?>
<div class="customers-table">
    <table class="data-table">
        <thead>
            <tr>
                <th>Priority</th>
                <th>Customer</th>
                <th>Phone</th>
                <th class="text-right">Outstanding</th>
                <th class="text-center">Invoices</th>
                <th class="text-center">Days Overdue</th>
                <th>Last Payment</th>
                <th class="text-center">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $customer): ?>
            <tr>
                <td>
                    <span class="priority-badge <?= $customer['priority'] ?>">
                        <?= strtoupper($customer['priority']) ?>
                    </span>
                </td>
                <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($customer['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($customer['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-right">
                    <strong style="color: var(--color-high); font-size: 1.05rem;">
                        $<?= number_format((float)$customer['balance_usd'], 2) ?>
                    </strong>
                </td>
                <td class="text-center"><?= (int)$customer['invoice_count'] ?></td>
                <td class="text-center">
                    <span style="color: var(--color-<?= $customer['priority'] ?>); font-weight: 600;">
                        <?= (int)$customer['days_overdue'] ?> days
                    </span>
                </td>
                <td>
                    <?= $customer['last_payment_date'] ? date('M d, Y', strtotime($customer['last_payment_date'])) : '<span style="color: #dc2626;">Never</span>' ?>
                </td>
                <td class="text-center">
                    <a href="?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-primary">
                        View Details
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="empty-state">
    <div class="empty-state-icon">✅</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">All Caught Up!</h3>
    <p>No outstanding receivables for your customers.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Chart.js Scripts -->
<script>
// AR Aging Chart
const agingCtx = document.getElementById('agingChart');
if (agingCtx) {
    new Chart(agingCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($agingLabels) ?>,
            datasets: [{
                data: <?= json_encode($agingValues) ?>,
                backgroundColor: [
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(234, 88, 12, 0.8)',
                    'rgba(220, 38, 38, 0.8)'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': $' + context.parsed.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// Collection Trend Chart
const trendCtx = document.getElementById('trendChart');
if (trendCtx && <?= json_encode($trendLabels) ?>.length > 0) {
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendLabels) ?>,
            datasets: [{
                label: 'Collections (USD)',
                data: <?= json_encode($trendValues) ?>,
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
</script>

<?php sales_portal_render_layout_end(); ?>
