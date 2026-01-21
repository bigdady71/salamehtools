<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/CommissionCalculator.php';

$user = require_accounting_access();
$pdo = db();

$calculator = new CommissionCalculator($pdo);

// Pre-select rep from URL
$preSelectedRep = (int)($_GET['rep'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));

// Handle POST - record payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid security token.');
        header('Location: commission_payments.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $salesRepId = (int)($_POST['sales_rep_id'] ?? 0);
        $commissionIds = array_map('intval', $_POST['commission_ids'] ?? []);
        $paymentMethod = $_POST['payment_method'] ?? '';
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $bankReference = trim($_POST['bank_reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validMethods = ['cash', 'bank_transfer', 'check', 'other'];

        if ($salesRepId <= 0) {
            flash('error', 'Please select a sales rep.');
        } elseif (empty($commissionIds)) {
            flash('error', 'Please select at least one commission to pay.');
        } elseif (!in_array($paymentMethod, $validMethods)) {
            flash('error', 'Invalid payment method.');
        } else {
            // Get the selected commissions and calculate total
            $placeholders = implode(',', array_fill(0, count($commissionIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, commission_amount_usd, commission_amount_lbp, period_start, period_end
                FROM commission_calculations
                WHERE id IN ($placeholders) AND sales_rep_id = ? AND status = 'approved'
            ");
            $params = array_merge($commissionIds, [$salesRepId]);
            $stmt->execute($params);
            $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($commissions)) {
                flash('error', 'No valid approved commissions found for this sales rep.');
            } else {
                $totalUsd = 0;
                $totalLbp = 0;
                $periodStart = null;
                $periodEnd = null;

                foreach ($commissions as $comm) {
                    $totalUsd += (float)$comm['commission_amount_usd'];
                    $totalLbp += (float)$comm['commission_amount_lbp'];
                    if ($periodStart === null || $comm['period_start'] < $periodStart) {
                        $periodStart = $comm['period_start'];
                    }
                    if ($periodEnd === null || $comm['period_end'] > $periodEnd) {
                        $periodEnd = $comm['period_end'];
                    }
                }

                try {
                    $pdo->beginTransaction();

                    // Generate payment reference
                    $paymentRef = 'COMM-' . date('Ymd') . '-' . str_pad((string)$salesRepId, 3, '0', STR_PAD_LEFT) . '-' . rand(100, 999);

                    // Insert payment record
                    $insertStmt = $pdo->prepare("
                        INSERT INTO commission_payments
                        (sales_rep_id, payment_reference, period_start, period_end, total_amount_usd, total_amount_lbp,
                         payment_method, payment_date, bank_reference, notes, paid_by)
                        VALUES
                        (:rep_id, :ref, :start, :end, :usd, :lbp, :method, :date, :bank_ref, :notes, :paid_by)
                    ");
                    $insertStmt->execute([
                        ':rep_id' => $salesRepId,
                        ':ref' => $paymentRef,
                        ':start' => $periodStart,
                        ':end' => $periodEnd,
                        ':usd' => $totalUsd,
                        ':lbp' => $totalLbp,
                        ':method' => $paymentMethod,
                        ':date' => $paymentDate,
                        ':bank_ref' => $bankReference ?: null,
                        ':notes' => $notes ?: null,
                        ':paid_by' => $user['id'],
                    ]);

                    $paymentId = $pdo->lastInsertId();

                    // Insert payment line items
                    $itemStmt = $pdo->prepare("
                        INSERT INTO commission_payment_items (payment_id, calculation_id, amount_usd, amount_lbp)
                        VALUES (:payment_id, :calc_id, :usd, :lbp)
                    ");

                    foreach ($commissions as $comm) {
                        $itemStmt->execute([
                            ':payment_id' => $paymentId,
                            ':calc_id' => $comm['id'],
                            ':usd' => $comm['commission_amount_usd'],
                            ':lbp' => $comm['commission_amount_lbp'],
                        ]);
                    }

                    // Mark commissions as paid
                    $calculator->markAsPaid(array_column($commissions, 'id'));

                    $pdo->commit();

                    flash('success', sprintf(
                        'Payment of %s recorded successfully. Reference: %s',
                        format_currency_usd($totalUsd),
                        $paymentRef
                    ));
                    header('Location: commission_payments.php');
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    flash('error', 'Database error: ' . $e->getMessage());
                }
            }
        }

        header('Location: commission_payments.php?rep=' . $salesRepId);
        exit;
    }
}

// Get sales reps with approved commissions
$repsStmt = $pdo->query("
    SELECT DISTINCT
        u.id,
        u.name,
        COALESCE(SUM(cc.commission_amount_usd), 0) as approved_total
    FROM users u
    JOIN commission_calculations cc ON cc.sales_rep_id = u.id AND cc.status = 'approved'
    WHERE u.role = 'sales_rep'
    GROUP BY u.id, u.name
    HAVING approved_total > 0
    ORDER BY u.name
");
$salesRepsWithApproved = $repsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get approved commissions for selected rep
$approvedCommissions = [];
if ($preSelectedRep > 0) {
    $stmt = $pdo->prepare("
        SELECT
            cc.id,
            cc.order_id,
            cc.invoice_id,
            cc.commission_type,
            cc.order_total_usd,
            cc.rate_percentage,
            cc.commission_amount_usd,
            cc.commission_amount_lbp,
            cc.period_start,
            cc.period_end,
            i.invoice_number,
            c.name as customer_name
        FROM commission_calculations cc
        JOIN invoices i ON i.id = cc.invoice_id
        JOIN orders o ON o.id = cc.order_id
        JOIN customers c ON c.id = o.customer_id
        WHERE cc.sales_rep_id = :rep_id AND cc.status = 'approved'
        ORDER BY cc.created_at DESC
    ");
    $stmt->execute([':rep_id' => $preSelectedRep]);
    $approvedCommissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get payment history
$paymentsStmt = $pdo->query("
    SELECT
        cp.id,
        cp.payment_reference,
        cp.period_start,
        cp.period_end,
        cp.total_amount_usd,
        cp.total_amount_lbp,
        cp.payment_method,
        cp.payment_date,
        cp.bank_reference,
        u.name as sales_rep_name,
        p.name as paid_by_name
    FROM commission_payments cp
    JOIN users u ON u.id = cp.sales_rep_id
    JOIN users p ON p.id = cp.paid_by
    ORDER BY cp.payment_date DESC, cp.created_at DESC
    LIMIT 50
");
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

accounting_render_layout_start([
    'title' => 'Commission Payments',
    'heading' => 'Commission Payments',
    'subtitle' => 'Record and track commission disbursements',
    'active' => 'commission_payments',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<?php if (!empty($salesRepsWithApproved)): ?>
<div class="card mb-2">
    <h2>Record New Payment</h2>

    <form method="GET" style="margin-bottom: 20px;">
        <div style="display: flex; gap: 12px; align-items: center;">
            <label style="font-weight: 500;">Select Sales Rep:</label>
            <select name="rep" class="filter-input" onchange="this.form.submit()">
                <option value="">Choose sales rep...</option>
                <?php foreach ($salesRepsWithApproved as $rep): ?>
                    <option value="<?= (int)$rep['id'] ?>" <?= $preSelectedRep == $rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['name']) ?> (<?= format_currency_usd((float)$rep['approved_total']) ?> approved)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($preSelectedRep > 0 && !empty($approvedCommissions)): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="sales_rep_id" value="<?= $preSelectedRep ?>">

            <div style="margin-bottom: 20px;">
                <h3 style="margin-bottom: 12px;">Select Commissions to Pay</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 30px;"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Period</th>
                            <th class="text-right">Order Total</th>
                            <th class="text-right">Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approvedCommissions as $comm): ?>
                            <tr>
                                <td><input type="checkbox" name="commission_ids[]" value="<?= (int)$comm['id'] ?>" class="comm-checkbox" onchange="updateTotal()"></td>
                                <td><code><?= htmlspecialchars($comm['invoice_number'] ?? '-') ?></code></td>
                                <td><?= htmlspecialchars($comm['customer_name']) ?></td>
                                <td><?= date('M Y', strtotime($comm['period_start'])) ?></td>
                                <td class="text-right"><?= format_currency_usd((float)$comm['order_total_usd']) ?></td>
                                <td class="text-right" style="font-weight: 600;" data-amount="<?= (float)$comm['commission_amount_usd'] ?>">
                                    <?= format_currency_usd((float)$comm['commission_amount_usd']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f0fdf4;">
                            <td colspan="5" class="text-right" style="font-weight: 600;">Selected Total:</td>
                            <td class="text-right" style="font-weight: 700; font-size: 1.1rem;" id="selectedTotal">$0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Payment Method</label>
                    <select name="payment_method" class="filter-input" style="width: 100%;" required>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="check">Check</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Payment Date</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" class="filter-input" style="width: 100%;" required>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Bank Reference (Optional)</label>
                    <input type="text" name="bank_reference" class="filter-input" style="width: 100%;" placeholder="Transaction #">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Notes (Optional)</label>
                    <input type="text" name="notes" class="filter-input" style="width: 100%;" placeholder="Additional notes">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Record Payment</button>
        </form>
    <?php elseif ($preSelectedRep > 0): ?>
        <p class="text-muted">No approved commissions found for this sales rep. Go to <a href="commissions.php">Commissions</a> to approve pending commissions first.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card mb-2">
    <h2>Record New Payment</h2>
    <p class="text-muted">No sales reps have approved commissions ready for payment. Go to <a href="commissions.php">Commissions</a> to calculate and approve commissions first.</p>
</div>
<?php endif; ?>

<div class="card">
    <h2>Payment History</h2>

    <table>
        <thead>
            <tr>
                <th>Reference</th>
                <th>Sales Rep</th>
                <th>Period</th>
                <th>Payment Date</th>
                <th>Method</th>
                <th class="text-right">Amount (USD)</th>
                <th>Paid By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted">No commission payments recorded yet</td>
                </tr>
            <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($payment['payment_reference']) ?></code></td>
                        <td><?= htmlspecialchars($payment['sales_rep_name']) ?></td>
                        <td><?= date('M Y', strtotime($payment['period_start'])) ?> - <?= date('M Y', strtotime($payment['period_end'])) ?></td>
                        <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $payment['payment_method'] === 'cash' ? 'success' : 'info' ?>">
                                <?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?>
                            </span>
                        </td>
                        <td class="text-right" style="font-weight: 600;"><?= format_currency_usd((float)$payment['total_amount_usd']) ?></td>
                        <td><?= htmlspecialchars($payment['paid_by_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.comm-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateTotal();
}

function updateTotal() {
    let total = 0;
    const checkboxes = document.querySelectorAll('.comm-checkbox:checked');
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const amountCell = row.querySelector('[data-amount]');
        if (amountCell) {
            total += parseFloat(amountCell.dataset.amount);
        }
    });
    document.getElementById('selectedTotal').textContent = '$' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php
accounting_render_layout_end();
