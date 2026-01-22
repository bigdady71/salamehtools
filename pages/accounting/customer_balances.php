<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Check if accounting columns exist
$columnsExist = false;
try {
    $checkStmt = $pdo->query("SELECT credit_limit_usd FROM customers LIMIT 1");
    $columnsExist = true;
} catch (PDOException $e) {
    // Columns don't exist yet
}

// Handle POST actions (only if columns exist)
if ($columnsExist && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid security token.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $customerId = (int)($_POST['customer_id'] ?? 0);

    if ($action === 'toggle_credit_hold' && $customerId > 0) {
        $newStatus = (int)($_POST['credit_hold'] ?? 0);
        $stmt = $pdo->prepare("UPDATE customers SET credit_hold = :status WHERE id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $customerId]);
        flash('success', 'Credit hold status updated.');
    } elseif ($action === 'update_credit_limit' && $customerId > 0) {
        $limitUsd = max(0, (float)($_POST['credit_limit_usd'] ?? 0));
        $limitLbp = max(0, (float)($_POST['credit_limit_lbp'] ?? 0));
        $stmt = $pdo->prepare("UPDATE customers SET credit_limit_usd = :limit_usd, credit_limit_lbp = :limit_lbp WHERE id = :id");
        $stmt->execute([':limit_usd' => $limitUsd, ':limit_lbp' => $limitLbp, ':id' => $customerId]);
        flash('success', 'Credit limit updated.');
    }

    header('Location: customer_balances.php?' . http_build_query($_GET));
    exit;
}

// Filters
$search = trim($_GET['search'] ?? '');
$salesRepId = trim($_GET['sales_rep'] ?? '');

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get sales reps for filter
$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build conditions
$conditions = ['1=1'];
$params = [];

if ($search !== '') {
    $conditions[] = "(c.name LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($salesRepId !== '') {
    $conditions[] = "c.assigned_sales_rep_id = :sales_rep_id";
    $params[':sales_rep_id'] = $salesRepId;
}

$whereClause = implode(' AND ', $conditions);

// Get customer data with calculated outstanding balance from invoices
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        c.assigned_sales_rep_id,
        COALESCE(u.name, 'Unassigned') as sales_rep_name,
        COALESCE(inv.outstanding_usd, 0) as balance_usd,
        COALESCE(inv.outstanding_lbp, 0) as balance_lbp,
        " . ($columnsExist ? "COALESCE(c.credit_limit_usd, 0)" : "0") . " as credit_limit_usd,
        " . ($columnsExist ? "COALESCE(c.credit_limit_lbp, 0)" : "0") . " as credit_limit_lbp,
        " . ($columnsExist ? "COALESCE(c.credit_hold, 0)" : "0") . " as credit_hold
    FROM customers c
    LEFT JOIN users u ON u.id = c.assigned_sales_rep_id
    LEFT JOIN (
        SELECT
            o.customer_id,
            SUM(i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding_usd,
            SUM(i.total_lbp - COALESCE(p.paid_lbp, 0)) as outstanding_lbp
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
            FROM payments GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE i.status IN ('issued', 'paid')
        GROUP BY o.customer_id
    ) inv ON inv.customer_id = c.id
    WHERE $whereClause AND c.is_active = 1
    ORDER BY balance_usd DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_customers,
        COUNT(CASE WHEN COALESCE(inv.outstanding_usd, 0) > 0 THEN 1 END) as customers_with_balance,
        COALESCE(SUM(inv.outstanding_usd), 0) as total_balance_usd
    FROM customers c
    LEFT JOIN (
        SELECT
            o.customer_id,
            SUM(i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding_usd
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd FROM payments GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE i.status IN ('issued', 'paid')
        GROUP BY o.customer_id
    ) inv ON inv.customer_id = c.id
    WHERE $whereClause AND c.is_active = 1
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

$totalCustomers = (int)$totals['total_customers'];
$totalPages = max(1, ceil($totalCustomers / $perPage));

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customer_balances_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Customer', 'Phone', 'Sales Rep', 'Outstanding USD', 'Outstanding LBP', 'Credit Limit USD', 'Credit Hold']);

    foreach ($customers as $row) {
        fputcsv($output, [
            $row['name'],
            $row['phone'],
            $row['sales_rep_name'],
            $row['balance_usd'],
            $row['balance_lbp'],
            $row['credit_limit_usd'],
            $row['credit_hold'] ? 'Yes' : 'No'
        ]);
    }

    fclose($output);
    exit;
}

accounting_render_layout_start([
    'title' => 'Customer Balances',
    'heading' => 'Customer Balances',
    'subtitle' => 'Manage customer accounts and outstanding balances',
    'active' => 'customer_balances',
    'user' => $user,
    'actions' => [
        ['label' => 'Adjust Balance', 'href' => 'balance_adjustment.php', 'variant' => 'primary'],
    ],
]);

accounting_render_flashes(consume_flashes());

if (!$columnsExist): ?>
<div class="card" style="background: #fef3c7; border-color: #fde68a;">
    <h2 style="color: #92400e;">Migration Required</h2>
    <p style="color: #92400e;">The accounting module database tables have not been created yet. Please run the migration:</p>
    <pre style="background: #fffbeb; padding: 12px; border-radius: 6px; margin: 12px 0; color: #78350f;">
SOURCE c:/xampp/htdocs/salamehtools/migrations/accounting_module_UP.sql;</pre>
    <p style="color: #92400e; margin-top: 12px;">After running the migration, refresh this page.</p>
</div>
<?php endif; ?>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Total Customers</div>
        <div class="value"><?= number_format($totalCustomers) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">With Outstanding Balance</div>
        <div class="value"><?= number_format((int)$totals['customers_with_balance']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Total Outstanding (USD)</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_balance_usd']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Credit Features</div>
        <div class="value"><?= $columnsExist ? 'Enabled' : 'Run Migration' ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Search name or phone..." value="<?= htmlspecialchars($search) ?>" class="filter-input" style="width: 200px;">

        <select name="sales_rep" class="filter-input">
            <option value="">All Sales Reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="customer_balances.php" class="btn">Clear</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="margin-left: auto;">Export CSV</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th>Sales Rep</th>
                <th class="text-right">Outstanding (USD)</th>
                <th class="text-right">Outstanding (LBP)</th>
                <?php if ($columnsExist): ?>
                <th class="text-right">Credit Limit</th>
                <th>Status</th>
                <th style="width: 180px;">Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="<?= $columnsExist ? 7 : 4 ?>" class="text-center text-muted">No customers found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                    <?php
                    $balanceUsd = (float)$customer['balance_usd'];
                    $limitUsd = (float)$customer['credit_limit_usd'];
                    $overLimit = $columnsExist && $limitUsd > 0 && $balanceUsd > $limitUsd;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($customer['name']) ?></strong>
                            <?php if ($customer['phone']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($customer['phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($customer['sales_rep_name']) ?></td>
                        <td class="text-right <?= $balanceUsd > 0 ? 'text-danger' : '' ?>">
                            <?= format_currency_usd($balanceUsd) ?>
                        </td>
                        <td class="text-right <?= (float)$customer['balance_lbp'] > 0 ? 'text-danger' : '' ?>">
                            <?= format_currency_lbp((float)$customer['balance_lbp']) ?>
                        </td>
                        <?php if ($columnsExist): ?>
                        <td class="text-right">
                            <?= format_currency_usd($limitUsd) ?>
                            <?php if ($overLimit): ?>
                                <br><span class="badge badge-danger">Over Limit</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($customer['credit_hold']): ?>
                                <span class="badge badge-danger">Credit Hold</span>
                            <?php else: ?>
                                <span class="badge badge-success">Normal</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="balance_adjustment.php?customer_id=<?= (int)$customer['id'] ?>" class="btn btn-sm">Adjust</a>

                                <form method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_credit_hold">
                                    <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                                    <input type="hidden" name="credit_hold" value="<?= $customer['credit_hold'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm <?= $customer['credit_hold'] ? 'btn-primary' : 'btn-danger' ?>" onclick="return confirm('<?= $customer['credit_hold'] ? 'Remove credit hold?' : 'Place customer on credit hold?' ?>')">
                                        <?= $customer['credit_hold'] ? 'Remove Hold' : 'Hold' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <div style="margin-top: 20px; display: flex; gap: 8px; justify-content: center;">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm">Previous</a>
            <?php endif; ?>

            <span style="padding: 6px 12px; color: var(--muted);">
                Page <?= $page ?> of <?= $totalPages ?>
            </span>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
accounting_render_layout_end();
