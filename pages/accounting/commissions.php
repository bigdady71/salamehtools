<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/CommissionCalculator.php';

$user = require_accounting_access();
$pdo = db();

// Check if commission tables exist
$tablesExist = false;
try {
    $pdo->query("SELECT 1 FROM commission_calculations LIMIT 1");
    $tablesExist = true;
} catch (PDOException $e) {
    // Tables don't exist yet
}

$calculator = $tablesExist ? new CommissionCalculator($pdo) : null;

// Period selection
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));

$periodStart = sprintf('%04d-%02d-01', $year, $month);
$periodEnd = date('Y-m-t', strtotime($periodStart));

// Handle POST actions (only if tables exist)
if ($tablesExist && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid security token.');
        header('Location: commissions.php?month=' . $month . '&year=' . $year);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'calculate') {
        $results = $calculator->calculateForPeriod($periodStart, $periodEnd);
        flash('success', sprintf(
            'Commission calculation complete: %d calculated, %d skipped. Total: %s',
            $results['calculated'],
            $results['skipped'],
            format_currency_usd($results['total_commission_usd'])
        ));

    } elseif ($action === 'approve_selected') {
        $selectedIds = array_map('intval', $_POST['commission_ids'] ?? []);
        if (!empty($selectedIds)) {
            $count = $calculator->approveCommissions($selectedIds, (int)$user['id']);
            flash('success', $count . ' commission(s) approved.');
        } else {
            flash('warning', 'No commissions selected.');
        }

    } elseif ($action === 'approve_all') {
        // Get all calculated commissions for period
        $stmt = $pdo->prepare("
            SELECT id FROM commission_calculations
            WHERE status = 'calculated'
            AND period_start >= :start AND period_end <= :end
        ");
        $stmt->execute([':start' => $periodStart, ':end' => $periodEnd]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($ids)) {
            $count = $calculator->approveCommissions($ids, (int)$user['id']);
            flash('success', $count . ' commission(s) approved.');
        } else {
            flash('info', 'No pending commissions to approve.');
        }
    }

    header('Location: commissions.php?month=' . $month . '&year=' . $year);
    exit;
}

// Initialize defaults
$totals = ['total_count' => 0, 'total_usd' => 0, 'pending_usd' => 0, 'approved_usd' => 0, 'paid_usd' => 0];
$summary = [];

if ($tablesExist) {
    // Get period totals
    $totalsStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_count,
            COALESCE(SUM(commission_amount_usd), 0) as total_usd,
            COALESCE(SUM(CASE WHEN status = 'calculated' THEN commission_amount_usd ELSE 0 END), 0) as pending_usd,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN commission_amount_usd ELSE 0 END), 0) as approved_usd,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount_usd ELSE 0 END), 0) as paid_usd
        FROM commission_calculations
        WHERE period_start >= :start AND period_end <= :end
    ");
    $totalsStmt->execute([':start' => $periodStart, ':end' => $periodEnd]);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

    // Get summary by sales rep
    $summary = $calculator->getSummaryByRep($periodStart, $periodEnd);
}

// View details for specific rep
$viewRepId = (int)($_GET['rep'] ?? 0);
$repDetails = [];
$repName = '';
if ($tablesExist && $viewRepId > 0) {
    $repDetails = $calculator->getCommissionsForRep($viewRepId, $periodStart, $periodEnd);
    $repStmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
    $repStmt->execute([':id' => $viewRepId]);
    $repName = $repStmt->fetchColumn() ?: '';
}

accounting_render_layout_start([
    'title' => 'Commissions',
    'heading' => 'Commission Management',
    'subtitle' => 'Calculate, approve, and track sales rep commissions',
    'active' => 'commissions',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="card mb-2">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <form method="GET" style="display: flex; gap: 12px; align-items: center;">
            <select name="month" class="filter-input">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="filter-input">
                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-primary">View Period</button>
        </form>

        <form method="POST" style="display: flex; gap: 10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="calculate">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Calculate commissions for all orders in this period?')">
                Calculate Commissions
            </button>
        </form>
    </div>
</div>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Total Commissions</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_usd']) ?></div>
        <div class="sub"><?= number_format((int)$totals['total_count']) ?> orders</div>
    </div>
    <div class="metric-card">
        <div class="label">Pending</div>
        <div class="value text-warning"><?= format_currency_usd((float)$totals['pending_usd']) ?></div>
        <div class="sub">Awaiting approval</div>
    </div>
    <div class="metric-card">
        <div class="label">Approved</div>
        <div class="value" style="color: #3b82f6;"><?= format_currency_usd((float)$totals['approved_usd']) ?></div>
        <div class="sub">Ready to pay</div>
    </div>
    <div class="metric-card">
        <div class="label">Paid</div>
        <div class="value text-success"><?= format_currency_usd((float)$totals['paid_usd']) ?></div>
        <div class="sub">Completed</div>
    </div>
</div>

<?php if ($viewRepId > 0 && !empty($repDetails)): ?>
    <div class="card" style="margin-top: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="margin: 0;">Commission Details: <?= htmlspecialchars($repName) ?></h2>
            <a href="commissions.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-sm">Back to Summary</a>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_selected">

            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th class="text-right">Order Total</th>
                        <th class="text-center">Rate</th>
                        <th class="text-right">Commission</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repDetails as $row): ?>
                        <tr>
                            <td>
                                <?php if ($row['status'] === 'calculated'): ?>
                                    <input type="checkbox" name="commission_ids[]" value="<?= (int)$row['id'] ?>">
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars($row['invoice_number'] ?? '-') ?></code></td>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td>
                                <span class="badge badge-<?= $row['commission_type'] === 'direct_sale' ? 'info' : 'success' ?>">
                                    <?= $row['commission_type'] === 'direct_sale' ? 'Direct' : 'Assigned' ?>
                                </span>
                            </td>
                            <td class="text-right"><?= format_currency_usd((float)$row['order_total_usd']) ?></td>
                            <td class="text-center"><?= number_format((float)$row['rate_percentage'], 2) ?>%</td>
                            <td class="text-right" style="font-weight: 600;"><?= format_currency_usd((float)$row['commission_amount_usd']) ?></td>
                            <td>
                                <?php
                                $statusBadge = match($row['status']) {
                                    'calculated' => 'badge-warning',
                                    'approved' => 'badge-info',
                                    'paid' => 'badge-success',
                                    default => 'badge-neutral'
                                };
                                ?>
                                <span class="badge <?= $statusBadge ?>"><?= ucfirst($row['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 16px; display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">Approve Selected</button>
            </div>
        </form>
    </div>

<?php else: ?>

    <div class="card" style="margin-top: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="margin: 0;">Commission by Sales Rep</h2>
            <?php if ((float)$totals['pending_usd'] > 0): ?>
                <form method="POST" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve_all">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Approve all pending commissions for this period?')">
                        Approve All Pending
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Sales Rep</th>
                    <th class="text-right">Orders</th>
                    <th class="text-right">Total Sales</th>
                    <th class="text-right">Total Commission</th>
                    <th class="text-right">Pending</th>
                    <th class="text-right">Approved</th>
                    <th class="text-right">Paid</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summary)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No commission data for this period. Click "Calculate Commissions" to process orders.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($summary as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['sales_rep_name']) ?></strong></td>
                            <td class="text-right"><?= number_format((int)$row['order_count']) ?></td>
                            <td class="text-right"><?= format_currency_usd((float)$row['total_sales_usd']) ?></td>
                            <td class="text-right" style="font-weight: 600;"><?= format_currency_usd((float)$row['total_commission_usd']) ?></td>
                            <td class="text-right text-warning"><?= format_currency_usd((float)$row['pending_usd']) ?></td>
                            <td class="text-right" style="color: #3b82f6;"><?= format_currency_usd((float)$row['approved_usd']) ?></td>
                            <td class="text-right text-success"><?= format_currency_usd((float)$row['paid_usd']) ?></td>
                            <td>
                                <?php if ((float)$row['total_commission_usd'] > 0): ?>
                                    <a href="commissions.php?month=<?= $month ?>&year=<?= $year ?>&rep=<?= (int)$row['sales_rep_id'] ?>" class="btn btn-sm">Details</a>
                                <?php endif; ?>
                                <?php if ((float)$row['approved_usd'] > 0): ?>
                                    <a href="commission_payments.php?rep=<?= (int)$row['sales_rep_id'] ?>&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-sm btn-primary">Pay</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('input[name="commission_ids[]"]');
    checkboxes.forEach(cb => cb.checked = source.checked);
}
</script>

<?php
accounting_render_layout_end();
