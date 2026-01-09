<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle expense submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_expense') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $category = trim((string)($_POST['category'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim((string)($_POST['currency'] ?? 'USD'));
        $date = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
        $description = trim((string)($_POST['description'] ?? ''));

        $errors = [];

        if ($category === '' || $category === 'select') {
            $errors[] = 'Please select an expense category.';
        }

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        if (!in_array($currency, ['USD', 'LBP'])) {
            $errors[] = 'Invalid currency selected.';
        }

        if ($description === '') {
            $errors[] = 'Please provide a description for this expense.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO sales_rep_expenses (
                        sales_rep_id, category, amount, currency, expense_date, description, status, created_at
                    ) VALUES (
                        :rep_id, :category, :amount, :currency, :expense_date, :description, 'approved', NOW()
                    )
                ");
                $stmt->execute([
                    ':rep_id' => $repId,
                    ':category' => $category,
                    ':amount' => $amount,
                    ':currency' => $currency,
                    ':expense_date' => $date,
                    ':description' => $description,
                ]);

                $flashes[] = [
                    'type' => 'success',
                    'title' => 'Expense Added',
                    'message' => 'Your expense has been recorded successfully.',
                    'dismissible' => true,
                ];

                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit;

            } catch (PDOException $e) {
                error_log("Failed to add expense: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to add expense. Please try again.',
                    'dismissible' => true,
                ];
            }
        } else {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Please fix the errors below:',
                'list' => $errors,
                'dismissible' => true,
            ];
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_expense') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid CSRF token.',
            'dismissible' => true,
        ];
    } else {
        $expenseId = (int)($_POST['expense_id'] ?? 0);

        // Verify expense belongs to this rep
        $checkStmt = $pdo->prepare("SELECT id FROM sales_rep_expenses WHERE id = :id AND sales_rep_id = :rep_id");
        $checkStmt->execute([':id' => $expenseId, ':rep_id' => $repId]);
        $expense = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($expense) {
            $deleteStmt = $pdo->prepare("DELETE FROM sales_rep_expenses WHERE id = :id");
            $deleteStmt->execute([':id' => $expenseId]);

            $flashes[] = [
                'type' => 'success',
                'title' => 'Expense Deleted',
                'message' => 'The expense has been removed.',
                'dismissible' => true,
            ];
        } else {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Cannot Delete',
                'message' => 'Expense not found.',
                'dismissible' => true,
            ];
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Check for success redirect
if (isset($_GET['success'])) {
    $flashes[] = [
        'type' => 'success',
        'title' => 'Expense Added Successfully',
        'message' => 'Your expense has been recorded successfully.',
        'dismissible' => true,
    ];
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$whereConditions = ['sales_rep_id = :rep_id'];
$params = [':rep_id' => $repId];

if ($statusFilter !== 'all') {
    $whereConditions[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

if ($dateFrom) {
    $whereConditions[] = 'expense_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = 'expense_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Get expenses
$expensesStmt = $pdo->prepare("
    SELECT *
    FROM sales_rep_expenses
    WHERE {$whereClause}
    ORDER BY expense_date DESC, created_at DESC
");
$expensesStmt->execute($params);
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN currency = 'LBP' THEN amount ELSE 0 END) as total_lbp,
        SUM(CASE WHEN status = 'approved' AND currency = 'USD' THEN amount ELSE 0 END) as approved_usd,
        SUM(CASE WHEN status = 'approved' AND currency = 'LBP' THEN amount ELSE 0 END) as approved_lbp,
        SUM(CASE WHEN status = 'pending' AND currency = 'USD' THEN amount ELSE 0 END) as pending_usd,
        SUM(CASE WHEN status = 'pending' AND currency = 'LBP' THEN amount ELSE 0 END) as pending_lbp
    FROM sales_rep_expenses
    WHERE {$whereClause}
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'My Expenses',
    'heading' => 'My Expenses',
    'subtitle' => 'Track and manage your business expenses',
    'active' => 'expenses',
    'user' => $user,
    'extra_head' => '<style>
        .expense-form {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
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
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent);
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
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover {
            background: #0284c7;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
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
        .filters {
            background: var(--bg-panel-alt);
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filters form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: end;
        }
        .expense-table {
            background: var(--bg-panel);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: var(--bg-panel-alt);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--muted);
        }
        td {
            padding: 12px;
            border-top: 1px solid var(--border);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-approved {
            background: #dcfce7;
            color: #166534;
        }
        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
            border: none;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-delete:hover {
            background: #fecaca;
        }
        .flash {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .flash.error {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }
        .flash.success {
            background: #dcfce7;
            border-color: #22c55e;
            color: #166534;
        }
        .flash h4 {
            margin: 0 0 8px;
            font-size: 1.1rem;
        }
        .flash p, .flash ul {
            margin: 4px 0;
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
                min-width: 600px;
            }
        }
    </style>',
]);

// Display flash messages
foreach ($flashes as $flash) {
    $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');

    echo '<div class="flash ' . $type . '">';
    echo '<h4>' . $title . '</h4>';
    echo '<p>' . $message . '</p>';

    if (!empty($flash['list'])) {
        echo '<ul>';
        foreach ($flash['list'] as $item) {
            echo '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}
?>

<!-- Add Expense Form -->
<div class="expense-form">
    <h2 style="margin-top: 0;">Add New Expense</h2>
    <form method="POST" action="">
        <input type="hidden" name="action" value="add_expense">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-grid">
            <div class="form-group">
                <label for="category">Category *</label>
                <select name="category" id="category" class="form-control" required>
                    <option value="select">Select Category</option>
                    <option value="fuel">Fuel / Gas</option>
                    <option value="meals">Meals & Entertainment</option>
                    <option value="maintenance">Vehicle Maintenance</option>
                    <option value="parking">Parking & Tolls</option>
                    <option value="phone">Phone & Internet</option>
                    <option value="supplies">Office Supplies</option>
                    <option value="accommodation">Accommodation</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="amount">Amount *</label>
                <input type="number" name="amount" id="amount" class="form-control" step="0.01" min="0.01" required>
            </div>

            <div class="form-group">
                <label for="currency">Currency *</label>
                <select name="currency" id="currency" class="form-control" required>
                    <option value="USD">USD</option>
                    <option value="LBP">LBP</option>
                </select>
            </div>

            <div class="form-group">
                <label for="expense_date">Date *</label>
                <input type="date" name="expense_date" id="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="description">Description *</label>
            <textarea name="description" id="description" class="form-control" rows="3" placeholder="Describe the expense..." required></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Add Expense</button>
    </form>
</div>

<!-- Stats Summary -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Expenses (Period)</div>
        <div class="stat-value">$<?= number_format((float)$totals['total_usd'], 2) ?></div>
        <div style="margin-top: 4px; font-size: 0.9rem; color: var(--muted);">
            L.L. <?= number_format((float)$totals['total_lbp'], 0) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Approved</div>
        <div class="stat-value" style="color: #22c55e;">$<?= number_format((float)$totals['approved_usd'], 2) ?></div>
        <div style="margin-top: 4px; font-size: 0.9rem; color: var(--muted);">
            L.L. <?= number_format((float)$totals['approved_lbp'], 0) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Pending Approval</div>
        <div class="stat-value" style="color: #f59e0b;">$<?= number_format((float)$totals['pending_usd'], 2) ?></div>
        <div style="margin-top: 4px; font-size: 0.9rem; color: var(--muted);">
            L.L. <?= number_format((float)$totals['pending_lbp'], 0) ?>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <form method="GET" action="">
        <div class="form-group" style="margin: 0;">
            <label for="status">Status</label>
            <select name="status" id="status" class="form-control">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label for="date_from">From Date</label>
            <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group" style="margin: 0;">
            <label for="date_to">To Date</label>
            <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 20px;">Filter</button>
    </form>
</div>

<!-- Expenses Table -->
<?php if (empty($expenses)): ?>
    <div class="empty-state">
        <div style="font-size: 3rem; margin-bottom: 16px;">💰</div>
        <h3>No Expenses Found</h3>
        <p>Add your first expense using the form above.</p>
    </div>
<?php else: ?>
    <div class="expense-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($expense['expense_date'])) ?></td>
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
                        <td>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this expense?');">
                                <input type="hidden" name="action" value="delete_expense">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="expense_id" value="<?= (int)$expense['id'] ?>">
                                <button type="submit" class="btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
sales_portal_render_layout_end();
