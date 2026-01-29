<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Get report type
$reportType = trim($_GET['report'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$salesRepId = (int)($_GET['sales_rep'] ?? 0);
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Get sales reps for filter
$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

$reportData = [];
$headers = [];
$reportTitle = '';

// Generate report based on type
if ($reportType !== '') {
    switch ($reportType) {
        case 'sales_summary':
            $reportTitle = 'Sales Summary Report';
            $headers = ['Date', 'Invoice #', 'Customer', 'Sales Rep', 'Total USD', 'Total LBP', 'Status'];

            $conditions = ["i.status IN ('issued', 'paid')"];
            $params = [':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59'];

            if ($salesRepId > 0) {
                $conditions[] = "o.sales_rep_id = :rep_id";
                $params[':rep_id'] = $salesRepId;
            }

            $whereClause = implode(' AND ', $conditions);

            $stmt = $pdo->prepare("
                SELECT
                    DATE(i.created_at) as date,
                    i.invoice_number,
                    c.name as customer,
                    COALESCE(u.name, 'N/A') as sales_rep,
                    i.total_usd,
                    i.total_lbp,
                    i.status
                FROM invoices i
                JOIN orders o ON o.id = i.order_id
                JOIN customers c ON c.id = o.customer_id
                LEFT JOIN users u ON u.id = o.sales_rep_id
                WHERE $whereClause
                AND i.created_at >= :date_from AND i.created_at <= :date_to
                ORDER BY i.created_at DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'commission_report':
            $reportTitle = 'Commission Report';
            $headers = ['Sales Rep', 'Orders', 'Total Sales USD', 'Commission USD', 'Pending', 'Approved', 'Paid'];

            $stmt = $pdo->prepare("
                SELECT
                    u.name as sales_rep,
                    COUNT(DISTINCT cc.order_id) as orders,
                    COALESCE(SUM(cc.order_total_usd), 0) as total_sales,
                    COALESCE(SUM(cc.commission_amount_usd), 0) as commission,
                    COALESCE(SUM(CASE WHEN cc.status = 'calculated' THEN cc.commission_amount_usd ELSE 0 END), 0) as pending,
                    COALESCE(SUM(CASE WHEN cc.status = 'approved' THEN cc.commission_amount_usd ELSE 0 END), 0) as approved,
                    COALESCE(SUM(CASE WHEN cc.status = 'paid' THEN cc.commission_amount_usd ELSE 0 END), 0) as paid
                FROM users u
                LEFT JOIN commission_calculations cc ON cc.sales_rep_id = u.id
                    AND cc.period_start >= :date_from AND cc.period_end <= :date_to
                WHERE u.role = 'sales_rep'
                GROUP BY u.id, u.name
                ORDER BY commission DESC
            ");
            $stmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'outstanding_balances':
            $reportTitle = 'Outstanding Balances Report';
            $headers = ['Customer', 'Phone', 'Sales Rep', 'Balance USD', 'Balance LBP', 'Credit Limit', 'Credit Hold'];

            $stmt = $pdo->query("
                SELECT
                    c.name as customer,
                    c.phone,
                    COALESCE(u.name, 'Unassigned') as sales_rep,
                    COALESCE(c.account_balance_usd, 0) as balance_usd,
                    COALESCE(c.account_balance_lbp, 0) as balance_lbp,
                    COALESCE(c.credit_limit_usd, 0) as credit_limit,
                    CASE WHEN COALESCE(c.credit_hold, 0) = 1 THEN 'Yes' ELSE 'No' END as credit_hold
                FROM customers c
                LEFT JOIN users u ON u.id = c.assigned_sales_rep_id
                WHERE c.is_active = 1 AND COALESCE(c.account_balance_usd, 0) > 0
                ORDER BY c.account_balance_usd DESC
            ");
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'aging_report':
            $reportTitle = 'Accounts Receivable Aging Report';
            $headers = ['Customer', 'Sales Rep', '0-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total Outstanding'];

            $stmt = $pdo->query("
                SELECT
                    c.name as customer,
                    COALESCE(u.name, 'N/A') as sales_rep,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) <= 30 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_0_30,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) BETWEEN 31 AND 60 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_31_60,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) BETWEEN 61 AND 90 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_61_90,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), i.created_at) > 90 THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as bucket_90_plus,
                    SUM(i.total_usd - COALESCE(p.paid_usd, 0)) as total_outstanding
                FROM invoices i
                JOIN orders o ON o.id = i.order_id
                JOIN customers c ON c.id = o.customer_id
                LEFT JOIN users u ON u.id = o.sales_rep_id
                LEFT JOIN (
                    SELECT invoice_id, SUM(amount_usd) as paid_usd FROM payments GROUP BY invoice_id
                ) p ON p.invoice_id = i.id
                WHERE i.status IN ('issued', 'paid')
                GROUP BY c.id, c.name, u.name
                HAVING total_outstanding > 0
                ORDER BY total_outstanding DESC
            ");
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'inventory_valuation':
            $reportTitle = 'Inventory Valuation Report';
            $headers = ['SKU', 'Product', 'Category', 'Qty on Hand', 'Cost Price', 'Sale Price', 'Cost Value', 'Retail Value'];

            $stmt = $pdo->query("
                SELECT
                    p.sku,
                    p.item_name as product,
                    p.topcat_name as category,
                    p.quantity_on_hand as qty,
                    COALESCE(p.cost_price_usd, 0) as cost_price,
                    COALESCE(p.wholesale_price_usd, 0) as sale_price,
                    (p.quantity_on_hand * COALESCE(p.cost_price_usd, 0)) as cost_value,
                    (p.quantity_on_hand * COALESCE(p.wholesale_price_usd, 0)) as retail_value
                FROM products p
                WHERE p.is_active = 1 AND p.quantity_on_hand > 0
                ORDER BY cost_value DESC
            ");
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }

    // Export to CSV
    if ($export && !empty($reportData)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $reportType . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);

        foreach ($reportData as $row) {
            fputcsv($output, array_values($row));
        }

        fclose($output);
        exit;
    }
}

accounting_render_layout_start([
    'title' => 'Reports',
    'heading' => 'Reports',
    'subtitle' => 'Generate and export financial reports',
    'active' => 'reports',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="card mb-2">
    <h2>Generate Report</h2>

    <form method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 500;">Report Type</label>
            <select name="report" class="filter-input" required>
                <option value="">Select report...</option>
                <option value="sales_summary" <?= $reportType === 'sales_summary' ? 'selected' : '' ?>>Sales Summary</option>
                <option value="commission_report" <?= $reportType === 'commission_report' ? 'selected' : '' ?>>Commission Report</option>
                <option value="outstanding_balances" <?= $reportType === 'outstanding_balances' ? 'selected' : '' ?>>Outstanding Balances</option>
                <option value="aging_report" <?= $reportType === 'aging_report' ? 'selected' : '' ?>>AR Aging Report</option>
                <option value="inventory_valuation" <?= $reportType === 'inventory_valuation' ? 'selected' : '' ?>>Inventory Valuation</option>
            </select>
        </div>

        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 500;">Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="filter-input">
        </div>

        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 500;">Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="filter-input">
        </div>

        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 500;">Sales Rep (Optional)</label>
            <select name="sales_rep" class="filter-input">
                <option value="">All</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= (int)$rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Generate Report</button>
    </form>
</div>

<?php if ($reportType !== '' && !empty($reportData)): ?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2 style="margin: 0;"><?= htmlspecialchars($reportTitle) ?></h2>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn">Export CSV</a>
    </div>

    <p class="text-muted mb-2">
        Period: <?= date('M j, Y', strtotime($dateFrom)) ?> - <?= date('M j, Y', strtotime($dateTo)) ?>
        | Records: <?= number_format(count($reportData)) ?>
    </p>

    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?= htmlspecialchars($header) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row): ?>
                    <tr>
                        <?php foreach ($row as $key => $value): ?>
                            <?php
                            $isNumeric = is_numeric($value) && !in_array($key, ['phone', 'invoice_number', 'sku']);
                            $isCurrency = strpos($key, 'usd') !== false || strpos($key, 'lbp') !== false ||
                                         strpos($key, 'balance') !== false || strpos($key, 'total') !== false ||
                                         strpos($key, 'price') !== false || strpos($key, 'value') !== false ||
                                         strpos($key, 'commission') !== false || strpos($key, 'bucket') !== false ||
                                         strpos($key, 'pending') !== false || strpos($key, 'approved') !== false ||
                                         strpos($key, 'paid') !== false || strpos($key, 'limit') !== false ||
                                         strpos($key, 'sales') !== false || strpos($key, 'outstanding') !== false;
                            ?>
                            <td class="<?= $isNumeric ? 'text-right' : '' ?>">
                                <?php if ($isCurrency): ?>
                                    <?= format_currency_usd((float)$value) ?>
                                <?php elseif ($key === 'date'): ?>
                                    <?= date('M j, Y', strtotime($value)) ?>
                                <?php elseif ($key === 'status'): ?>
                                    <span class="badge badge-<?= $value === 'paid' ? 'success' : 'info' ?>"><?= ucfirst($value) ?></span>
                                <?php elseif ($key === 'invoice_number' || $key === 'sku'): ?>
                                    <code><?= htmlspecialchars($value ?? '-') ?></code>
                                <?php elseif (is_numeric($value)): ?>
                                    <?= number_format((float)$value) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($value ?? '-') ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($reportType !== ''): ?>
<div class="card">
    <p class="text-muted text-center">No data found for the selected report and filters.</p>
</div>
<?php else: ?>
<div class="card">
    <h2>Available Reports</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 16px;">
        <div style="padding: 16px; background: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0;">
            <strong style="color: #059669;">Sales Summary</strong>
            <p class="text-muted" style="margin: 8px 0 0; font-size: 0.9rem;">List of all invoices with customer and sales rep details.</p>
        </div>
        <div style="padding: 16px; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd;">
            <strong style="color: #0284c7;">Commission Report</strong>
            <p class="text-muted" style="margin: 8px 0 0; font-size: 0.9rem;">Commission breakdown by sales representative.</p>
        </div>
        <div style="padding: 16px; background: #fef3c7; border-radius: 8px; border: 1px solid #fde68a;">
            <strong style="color: #d97706;">Outstanding Balances</strong>
            <p class="text-muted" style="margin: 8px 0 0; font-size: 0.9rem;">Customers with positive account balances.</p>
        </div>
        <div style="padding: 16px; background: #fee2e2; border-radius: 8px; border: 1px solid #fecaca;">
            <strong style="color: #dc2626;">AR Aging Report</strong>
            <p class="text-muted" style="margin: 8px 0 0; font-size: 0.9rem;">Receivables grouped by age buckets (0-30, 31-60, 61-90, 90+).</p>
        </div>
        <div style="padding: 16px; background: #f3f4f6; border-radius: 8px; border: 1px solid #e5e7eb;">
            <strong style="color: #374151;">Inventory Valuation</strong>
            <p class="text-muted" style="margin: 8px 0 0; font-size: 0.9rem;">Stock value at cost and retail prices.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
accounting_render_layout_end();
