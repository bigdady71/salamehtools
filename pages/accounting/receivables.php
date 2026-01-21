<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Filters
$search = trim($_GET['search'] ?? '');
$salesRepId = trim($_GET['sales_rep'] ?? '');
$agingBucket = trim($_GET['aging'] ?? '');

// Get sales reps for filter
$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build conditions
$conditions = ["i.status IN ('issued', 'paid')"];
$havingConditions = ["outstanding_usd > 0"];
$params = [];

if ($search !== '') {
    $conditions[] = "(c.name LIKE :search OR c.phone LIKE :search OR i.invoice_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($salesRepId !== '') {
    $conditions[] = "o.sales_rep_id = :sales_rep_id";
    $params[':sales_rep_id'] = $salesRepId;
}

$whereClause = implode(' AND ', $conditions);

// Get aging summary
$agingStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) <= 30 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_0_30,
        SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) BETWEEN 31 AND 60 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_31_60,
        SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) BETWEEN 61 AND 90 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_61_90,
        SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) > 90 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_90_plus,
        SUM(i.total_usd - COALESCE(p.paid_usd, 0)) as total_outstanding
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd
        FROM payments GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
    HAVING total_outstanding > 0
");
$aging = $agingStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'bucket_0_30' => 0,
    'bucket_31_60' => 0,
    'bucket_61_90' => 0,
    'bucket_90_plus' => 0,
    'total_outstanding' => 0
];

// Build aging filter condition
if ($agingBucket === '0-30') {
    $havingConditions[] = "days_outstanding <= 30";
} elseif ($agingBucket === '31-60') {
    $havingConditions[] = "days_outstanding BETWEEN 31 AND 60";
} elseif ($agingBucket === '61-90') {
    $havingConditions[] = "days_outstanding BETWEEN 61 AND 90";
} elseif ($agingBucket === '90+') {
    $havingConditions[] = "days_outstanding > 90";
}

$havingClause = implode(' AND ', $havingConditions);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get receivables data
$stmt = $pdo->prepare("
    SELECT
        i.id as invoice_id,
        i.invoice_number,
        i.created_at as invoice_date,
        i.total_usd,
        i.total_lbp,
        COALESCE(p.paid_usd, 0) as paid_usd,
        COALESCE(p.paid_lbp, 0) as paid_lbp,
        (i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding_usd,
        (i.total_lbp - COALESCE(p.paid_lbp, 0)) as outstanding_lbp,
        DATEDIFF(CURDATE(), i.created_at) as days_outstanding,
        c.id as customer_id,
        c.name as customer_name,
        c.phone as customer_phone,
        COALESCE(u.name, 'N/A') as sales_rep_name
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.sales_rep_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
        FROM payments GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE $whereClause
    HAVING $havingClause
    ORDER BY days_outstanding DESC, outstanding_usd DESC
    LIMIT $perPage OFFSET $offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$receivables = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare("
        SELECT
            i.invoice_number,
            DATE(i.created_at) as invoice_date,
            c.name as customer_name,
            COALESCE(u.name, 'N/A') as sales_rep_name,
            i.total_usd,
            COALESCE(p.paid_usd, 0) as paid_usd,
            (i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding_usd,
            DATEDIFF(CURDATE(), i.created_at) as days_outstanding
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users u ON u.id = o.sales_rep_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd
            FROM payments GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE $whereClause
        HAVING $havingClause
        ORDER BY days_outstanding DESC, outstanding_usd DESC
    ");
    foreach ($params as $key => $value) {
        $exportStmt->bindValue($key, $value);
    }
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="receivables_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice #', 'Invoice Date', 'Customer', 'Sales Rep', 'Total USD', 'Paid USD', 'Outstanding USD', 'Days Outstanding']);

    foreach ($exportData as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

accounting_render_layout_start([
    'title' => 'Receivables',
    'heading' => 'Accounts Receivable',
    'subtitle' => 'Outstanding invoices and aging analysis',
    'active' => 'receivables',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="metric-grid" style="grid-template-columns: repeat(5, 1fr);">
    <div class="metric-card">
        <div class="label">0-30 Days</div>
        <div class="value text-success"><?= format_currency_usd((float)$aging['bucket_0_30']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">31-60 Days</div>
        <div class="value text-warning"><?= format_currency_usd((float)$aging['bucket_31_60']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">61-90 Days</div>
        <div class="value" style="color: #ea580c;"><?= format_currency_usd((float)$aging['bucket_61_90']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">90+ Days</div>
        <div class="value text-danger"><?= format_currency_usd((float)$aging['bucket_90_plus']) ?></div>
    </div>
    <div class="metric-card" style="background: linear-gradient(135deg, #1f6feb 0%, #3b82f6 100%); color: white;">
        <div class="label" style="color: rgba(255,255,255,0.8);">Total Outstanding</div>
        <div class="value" style="color: white;"><?= format_currency_usd((float)$aging['total_outstanding']) ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Search customer or invoice..." value="<?= htmlspecialchars($search) ?>" class="filter-input" style="width: 200px;">

        <select name="sales_rep" class="filter-input">
            <option value="">All Sales Reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="aging" class="filter-input">
            <option value="">All Ages</option>
            <option value="0-30" <?= $agingBucket === '0-30' ? 'selected' : '' ?>>0-30 Days</option>
            <option value="31-60" <?= $agingBucket === '31-60' ? 'selected' : '' ?>>31-60 Days</option>
            <option value="61-90" <?= $agingBucket === '61-90' ? 'selected' : '' ?>>61-90 Days</option>
            <option value="90+" <?= $agingBucket === '90+' ? 'selected' : '' ?>>90+ Days</option>
        </select>

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="receivables.php" class="btn">Clear</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="margin-left: auto;">Export CSV</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Sales Rep</th>
                <th class="text-right">Invoice Total</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Outstanding</th>
                <th class="text-center">Age</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($receivables)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted">No outstanding receivables found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($receivables as $row): ?>
                    <?php
                    $days = (int)$row['days_outstanding'];
                    $ageBadge = 'badge-success';
                    if ($days > 90) {
                        $ageBadge = 'badge-danger';
                    } elseif ($days > 60) {
                        $ageBadge = 'badge-warning';
                    } elseif ($days > 30) {
                        $ageBadge = 'badge-info';
                    }
                    ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($row['invoice_number'] ?? '-') ?></code>
                            <br><small class="text-muted"><?= date('M j, Y', strtotime($row['invoice_date'])) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars($row['customer_name']) ?>
                            <?php if ($row['customer_phone']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($row['customer_phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['sales_rep_name']) ?></td>
                        <td class="text-right"><?= format_currency_usd((float)$row['total_usd']) ?></td>
                        <td class="text-right text-success"><?= format_currency_usd((float)$row['paid_usd']) ?></td>
                        <td class="text-right text-danger" style="font-weight: 600;"><?= format_currency_usd((float)$row['outstanding_usd']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $ageBadge ?>"><?= $days ?> days</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
accounting_render_layout_end();
