<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/admin_page.php';

// Admin only
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied - Admins only');
}

$pdo = db();

// Filters
$repFilter = $_GET['rep'] ?? 'all';
$categoryFilter = $_GET['category'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$whereConditions = ['1=1'];
$params = [];

if ($repFilter !== 'all') {
    $whereConditions[] = 'e.sales_rep_id = :rep_id';
    $params[':rep_id'] = (int)$repFilter;
}

if ($categoryFilter !== 'all') {
    $whereConditions[] = 'e.category = :category';
    $params[':category'] = $categoryFilter;
}

if ($dateFrom) {
    $whereConditions[] = 'e.expense_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = 'e.expense_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Get expenses with sales rep name
$expensesStmt = $pdo->prepare("
    SELECT
        e.*,
        u.name as rep_name
    FROM sales_rep_expenses e
    JOIN users u ON e.sales_rep_id = u.id
    WHERE {$whereClause}
    ORDER BY e.expense_date DESC, e.created_at DESC
");
$expensesStmt->execute($params);
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN e.currency = 'LBP' THEN e.amount ELSE 0 END) as total_lbp,
        COUNT(*) as expense_count
    FROM sales_rep_expenses e
    WHERE {$whereClause}
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

// Get totals by category
$categoryTotalsStmt = $pdo->prepare("
    SELECT
        e.category,
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN e.currency = 'LBP' THEN e.amount ELSE 0 END) as total_lbp,
        COUNT(*) as count
    FROM sales_rep_expenses e
    WHERE {$whereClause}
    GROUP BY e.category
    ORDER BY total_usd DESC
");
$categoryTotalsStmt->execute($params);
$categoryTotals = $categoryTotalsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals by sales rep
$repTotalsStmt = $pdo->prepare("
    SELECT
        e.sales_rep_id,
        u.name as rep_name,
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN e.currency = 'LBP' THEN e.amount ELSE 0 END) as total_lbp,
        COUNT(*) as count
    FROM sales_rep_expenses e
    JOIN users u ON e.sales_rep_id = u.id
    WHERE {$whereClause}
    GROUP BY e.sales_rep_id, u.name
    ORDER BY total_usd DESC
");
$repTotalsStmt->execute($params);
$repTotals = $repTotalsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all sales reps for filter
$repsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$salesReps = $repsStmt->fetchAll(PDO::FETCH_ASSOC);

$maxCategoryUsd = 0.0;
foreach ($categoryTotals as $catTotal) {
    $maxCategoryUsd = max($maxCategoryUsd, (float)($catTotal['total_usd'] ?? 0));
}
if ($maxCategoryUsd <= 0) {
    $maxCategoryUsd = 1;
}

$maxRepUsd = 0.0;
foreach ($repTotals as $repTotal) {
    $maxRepUsd = max($maxRepUsd, (float)($repTotal['total_usd'] ?? 0));
}
if ($maxRepUsd <= 0) {
    $maxRepUsd = 1;
}

// Start admin layout
admin_render_layout_start([
    'title' => 'Sales Rep Expenses',
    'heading' => 'Sales Rep Expenses',
    'subtitle' => 'View and analyze all sales representative expenses',
    'active' => 'expenses',
    'user' => $user,
    'extra_head' => '<style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-panel);
            padding: 18px;
            border-radius: 14px;
            border: 1px solid var(--border);
            display: flex;
            gap: 14px;
            align-items: center;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: var(--bg-panel-alt);
            border: 1px solid var(--border);
        }
        .stat-body {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text);
        }
        .stat-sublabel {
            margin-top: 4px;
            font-size: 0.9rem;
            color: var(--muted);
        }
        .filters {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 14px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .filters h3 {
            margin-bottom: 14px;
            font-size: 1.1rem;
        }
        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-weight: 600;
            font-size: 0.9rem;
        }
        .form-control {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            min-width: 150px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent) 0%, #0ea5e9 100%);
            color: white;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .breakdowns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        .breakdown-card {
            background: var(--bg-panel);
            padding: 22px;
            border-radius: 14px;
            border: 1px solid var(--border);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .breakdown-card h3 {
            margin-bottom: 16px;
            font-size: 1.2rem;
        }
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
            gap: 12px;
        }
        .breakdown-item:last-child {
            border-bottom: none;
        }
        .breakdown-label {
            font-weight: 500;
        }
        .breakdown-value {
            font-weight: 700;
            color: var(--accent);
        }
        .breakdown-progress {
            height: 6px;
            border-radius: 999px;
            background: var(--bg-panel-alt);
            overflow: hidden;
            margin-top: 6px;
        }
        .breakdown-progress span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #0ea5e9);
        }
        .expense-table {
            background: var(--bg-panel);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: var(--bg-panel-alt);
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        td {
            padding: 14px 12px;
            border-top: 1px solid var(--border);
        }
        tr:hover {
            background: var(--bg-panel-alt);
        }
        tbody tr:nth-child(2n) {
            background: rgba(148, 163, 184, 0.06);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-approved {
            background: #dcfce7;
            color: #166534;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        @media (max-width: 768px) {
            .expense-table {
                overflow-x: auto;
            }
            table {
                min-width: 800px;
            }
        }
    </style>',
]);
?>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">💳</div>
        <div class="stat-body">
            <div class="stat-label">Total Expenses (Period)</div>
            <div class="stat-value">$<?= number_format((float)($totals['total_usd'] ?? 0), 2) ?></div>
            <div class="stat-sublabel">L.L. <?= number_format((float)($totals['total_lbp'] ?? 0), 0) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">🧾</div>
        <div class="stat-body">
            <div class="stat-label">Total Transactions</div>
            <div class="stat-value"><?= number_format((int)($totals['expense_count'] ?? 0)) ?></div>
            <div class="stat-sublabel">Expense entries</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">📈</div>
        <div class="stat-body">
            <div class="stat-label">Average per Entry</div>
            <div class="stat-value">$<?= ($totals['expense_count'] ?? 0) > 0 ? number_format(($totals['total_usd'] ?? 0) / $totals['expense_count'], 2) : '0.00' ?></div>
            <div class="stat-sublabel">USD average</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <h3>Filters</h3>
    <form method="GET" action="">
        <div class="form-group">
            <label for="rep">Sales Rep</label>
            <select name="rep" id="rep" class="form-control">
                <option value="all" <?= $repFilter === 'all' ? 'selected' : '' ?>>All Sales Reps</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= (int)$rep['id'] ?>" <?= $repFilter == $rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="category">Category</label>
            <select name="category" id="category" class="form-control">
                <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All Categories</option>
                <option value="fuel" <?= $categoryFilter === 'fuel' ? 'selected' : '' ?>>Fuel / Gas</option>
                <option value="meals" <?= $categoryFilter === 'meals' ? 'selected' : '' ?>>Meals & Entertainment</option>
                <option value="maintenance" <?= $categoryFilter === 'maintenance' ? 'selected' : '' ?>>Vehicle Maintenance</option>
                <option value="parking" <?= $categoryFilter === 'parking' ? 'selected' : '' ?>>Parking & Tolls</option>
                <option value="phone" <?= $categoryFilter === 'phone' ? 'selected' : '' ?>>Phone & Internet</option>
                <option value="supplies" <?= $categoryFilter === 'supplies' ? 'selected' : '' ?>>Office Supplies</option>
                <option value="accommodation" <?= $categoryFilter === 'accommodation' ? 'selected' : '' ?>>Accommodation</option>
                <option value="other" <?= $categoryFilter === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
        </div>

        <div class="form-group">
            <label for="date_from">From Date</label>
            <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
            <label for="date_to">To Date</label>
            <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <button type="submit" class="btn btn-primary">Apply Filters</button>
        <a href="expenses.php" class="btn" style="background: var(--bg-panel-alt); color: var(--text); text-decoration:none; text-align:center;">Clear</a>
    </form>
</div>

<!-- Breakdowns -->
<div class="breakdowns">
    <!-- By Category -->
    <div class="breakdown-card">
        <h3>Expenses by Category</h3>
        <?php if (empty($categoryTotals)): ?>
            <p style="color: var(--muted);">No data available</p>
        <?php else: ?>
            <?php foreach ($categoryTotals as $cat): ?>
                <div class="breakdown-item">
                    <div>
                        <div class="breakdown-label"><?= htmlspecialchars(ucfirst($cat['category']), ENT_QUOTES, 'UTF-8') ?> (<?= (int)$cat['count'] ?>)</div>
                        <div class="breakdown-progress">
                            <span style="width: <?= min(100, ((float)$cat['total_usd'] / $maxCategoryUsd) * 100) ?>%;"></span>
                        </div>
                    </div>
                    <span class="breakdown-value">$<?= number_format((float)$cat['total_usd'], 2) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- By Sales Rep -->
    <div class="breakdown-card">
        <h3>Expenses by Sales Rep</h3>
        <?php if (empty($repTotals)): ?>
            <p style="color: var(--muted);">No data available</p>
        <?php else: ?>
            <?php foreach ($repTotals as $rep): ?>
                <div class="breakdown-item">
                    <div>
                        <div class="breakdown-label"><?= htmlspecialchars($rep['rep_name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$rep['count'] ?>)</div>
                        <div class="breakdown-progress">
                            <span style="width: <?= min(100, ((float)$rep['total_usd'] / $maxRepUsd) * 100) ?>%;"></span>
                        </div>
                    </div>
                    <span class="breakdown-value">$<?= number_format((float)$rep['total_usd'], 2) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Expenses Table -->
<?php if (empty($expenses)): ?>
    <div class="expense-table">
        <div class="empty-state">
            <div style="font-size: 3rem; margin-bottom: 16px;">💰</div>
            <h3>No Expenses Found</h3>
            <p>No expenses match your current filters.</p>
        </div>
    </div>
<?php else: ?>
    <div class="expense-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Sales Rep</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($expense['expense_date'])) ?></td>
                        <td><strong><?= htmlspecialchars($expense['rep_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars(ucfirst($expense['category']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($expense['description'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <strong><?= $expense['currency'] === 'USD' ? '$' : 'L.L. ' ?><?= number_format((float)$expense['amount'], $expense['currency'] === 'USD' ? 2 : 0) ?></strong>
                        </td>
                        <td>
                            <span class="status-badge status-<?= htmlspecialchars($expense['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(ucfirst($expense['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php admin_render_layout_end(); ?>
