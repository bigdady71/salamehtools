<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
$creditHold = trim($_GET['credit_hold'] ?? '');
$balanceMin = trim($_GET['balance_min'] ?? '');
$sortBy = trim($_GET['sort'] ?? 'balance_usd');
$sortDir = strtoupper(trim($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

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

if ($creditHold === '1') {
    $conditions[] = "COALESCE(c.credit_hold, 0) = 1";
} elseif ($creditHold === '0') {
    $conditions[] = "COALESCE(c.credit_hold, 0) = 0";
}

if ($balanceMin !== '' && is_numeric($balanceMin)) {
    $conditions[] = "COALESCE(c.account_balance_usd, 0) >= :balance_min";
    $params[':balance_min'] = (float)$balanceMin;
}

$whereClause = implode(' AND ', $conditions);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_customers,
        SUM(CASE WHEN COALESCE(c.account_balance_usd, 0) > 0 THEN 1 ELSE 0 END) as customers_with_balance,
        COALESCE(SUM(c.account_balance_usd), 0) as total_balance_usd,
        COALESCE(SUM(c.account_balance_lbp), 0) as total_balance_lbp,
        SUM(CASE WHEN COALESCE(c.credit_hold, 0) = 1 THEN 1 ELSE 0 END) as on_credit_hold
    FROM customers c
    WHERE $whereClause AND c.is_active = 1
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

$totalCustomers = (int)$totals['total_customers'];
$totalPages = ceil($totalCustomers / $perPage);

// Validate sort column
$allowedSorts = ['name', 'balance_usd', 'balance_lbp', 'credit_limit_usd', 'sales_rep_name'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'balance_usd';
}

$orderByClause = match($sortBy) {
    'balance_usd' => 'COALESCE(c.account_balance_usd, 0)',
    'balance_lbp' => 'COALESCE(c.account_balance_lbp, 0)',
    'credit_limit_usd' => 'COALESCE(c.credit_limit_usd, 0)',
    'sales_rep_name' => 'u.name',
    default => 'c.name'
};

// Get customers
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        c.assigned_sales_rep_id,
        COALESCE(u.name, 'Unassigned') as sales_rep_name,
        COALESCE(c.account_balance_usd, 0) as balance_usd,
        COALESCE(c.account_balance_lbp, 0) as balance_lbp,
        COALESCE(c.credit_limit_usd, 0) as credit_limit_usd,
        COALESCE(c.credit_limit_lbp, 0) as credit_limit_lbp,
        COALESCE(c.credit_hold, 0) as credit_hold,
        COALESCE(c.payment_terms_days, 0) as payment_terms_days
    FROM customers c
    LEFT JOIN users u ON u.id = c.assigned_sales_rep_id
    WHERE $whereClause AND c.is_active = 1
    ORDER BY $orderByClause $sortDir
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare("
        SELECT
            c.name,
            c.phone,
            COALESCE(u.name, 'Unassigned') as sales_rep_name,
            COALESCE(c.account_balance_usd, 0) as balance_usd,
            COALESCE(c.account_balance_lbp, 0) as balance_lbp,
            COALESCE(c.credit_limit_usd, 0) as credit_limit_usd,
            COALESCE(c.credit_hold, 0) as credit_hold
        FROM customers c
        LEFT JOIN users u ON u.id = c.assigned_sales_rep_id
        WHERE $whereClause AND c.is_active = 1
        ORDER BY $orderByClause $sortDir
    ");
    foreach ($params as $key => $value) {
        $exportStmt->bindValue($key, $value);
    }
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customer_balances_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Customer', 'Phone', 'Sales Rep', 'Balance USD', 'Balance LBP', 'Credit Limit USD', 'Credit Hold']);

    foreach ($exportData as $row) {
        $row['credit_hold'] = $row['credit_hold'] ? 'Yes' : 'No';
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

// Helper for sort links
function sortLink(string $column, string $label, string $currentSort, string $currentDir): string
{
    $newDir = ($currentSort === $column && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentDir === 'DESC' ? ' ↓' : ' ↑';
    }
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = $newDir;
    unset($params['page']);
    return '<a href="?' . http_build_query($params) . '" style="color:inherit;text-decoration:none;">' . htmlspecialchars($label) . $arrow . '</a>';
}

accounting_render_layout_start([
    'title' => 'Customer Balances',
    'heading' => 'Customer Balances',
    'subtitle' => 'Manage customer accounts, credit limits, and balances',
    'active' => 'customer_balances',
    'user' => $user,
    'actions' => [
        ['label' => 'Adjust Balance', 'href' => 'balance_adjustment.php', 'variant' => 'primary'],
    ],
]);

accounting_render_flashes(consume_flashes());
?>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Total Customers</div>
        <div class="value"><?= number_format($totalCustomers) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">With Balance</div>
        <div class="value"><?= number_format((int)$totals['customers_with_balance']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Total Outstanding (USD)</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_balance_usd']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">On Credit Hold</div>
        <div class="value"><?= number_format((int)$totals['on_credit_hold']) ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Search name or phone..." value="<?= htmlspecialchars($search) ?>" class="filter-input" style="width: 180px;">

        <select name="sales_rep" class="filter-input">
            <option value="">All Sales Reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="credit_hold" class="filter-input">
            <option value="">All Credit Status</option>
            <option value="1" <?= $creditHold === '1' ? 'selected' : '' ?>>On Credit Hold</option>
            <option value="0" <?= $creditHold === '0' ? 'selected' : '' ?>>Normal</option>
        </select>

        <input type="number" name="balance_min" placeholder="Min balance USD" value="<?= htmlspecialchars($balanceMin) ?>" class="filter-input" style="width: 140px;" step="0.01">

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="customer_balances.php" class="btn">Clear</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="margin-left: auto;">Export CSV</a>
    </form>

    <table>
        <thead>
            <tr>
                <th><?= sortLink('name', 'Customer', $sortBy, $sortDir) ?></th>
                <th><?= sortLink('sales_rep_name', 'Sales Rep', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('balance_usd', 'Balance (USD)', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('balance_lbp', 'Balance (LBP)', $sortBy, $sortDir) ?></th>
                <th class="text-right"><?= sortLink('credit_limit_usd', 'Credit Limit', $sortBy, $sortDir) ?></th>
                <th>Status</th>
                <th style="width: 200px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted">No customers found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                    <?php
                    $balanceUsd = (float)$customer['balance_usd'];
                    $limitUsd = (float)$customer['credit_limit_usd'];
                    $overLimit = $limitUsd > 0 && $balanceUsd > $limitUsd;
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
