<?php
/**
 * DEMO PAGE: Advanced Filters & Export
 *
 * This demo page shows how to integrate FilterBuilder and ExportManager
 * Use this as a reference when updating other admin pages
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/FilterBuilder.php';
require_once __DIR__ . '/../../includes/ExportManager.php';
require_once __DIR__ . '/../../includes/export_buttons.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Filters & Export Demo';

// ======================
// BUILD FILTERS
// ======================
$filter = new FilterBuilder();

// Add various filter types
$filter->addTextFilter('search', 'i.invoice_number');  // Text search
$filter->addSelectFilter('status', 'i.status', ['draft', 'issued', 'paid', 'voided']);  // Dropdown
$filter->addDateRangeFilter('date', 'i.created_at');  // Date range
$filter->addSelectFilter('customer', 'o.customer_id');  // Customer filter
$filter->addNumericRangeFilter('amount', 'i.total_usd');  // Amount range

$whereClause = $filter->buildWhereClause();
$params = $filter->getParameters();

// ======================
// FETCH DATA
// ======================
$sql = "
    SELECT
        i.id,
        i.invoice_number,
        i.status,
        i.total_usd,
        i.total_lbp,
        i.created_at,
        i.issued_at,
        c.name as customer_name,
        o.order_number,
        COALESCE(paid.paid_usd, 0) as paid_usd,
        COALESCE(paid.paid_lbp, 0) as paid_lbp,
        (i.total_usd - COALESCE(paid.paid_usd, 0)) as balance_usd
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN (
        SELECT invoice_id,
               SUM(amount_usd) as paid_usd,
               SUM(amount_lbp) as paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    {$whereClause}
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT 500
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $invoices = [];
    $error = "Database error: " . $e->getMessage();
}

// ======================
// HANDLE EXPORT
// ======================
if (isset($_GET['export']) && !empty($invoices)) {
    // Prepare data for export (format and label columns)
    $exportData = [];
    foreach ($invoices as $invoice) {
        $exportData[] = [
            'Invoice #' => $invoice['invoice_number'],
            'Customer' => $invoice['customer_name'],
            'Order #' => $invoice['order_number'],
            'Status' => ucfirst($invoice['status']),
            'Amount (USD)' => '$' . number_format($invoice['total_usd'], 2),
            'Paid (USD)' => '$' . number_format($invoice['paid_usd'], 2),
            'Balance (USD)' => '$' . number_format($invoice['balance_usd'], 2),
            'Created Date' => date('Y-m-d', strtotime($invoice['created_at'])),
            'Issued Date' => $invoice['issued_at'] ? date('Y-m-d', strtotime($invoice['issued_at'])) : 'Not issued'
        ];
    }

    $exporter = new ExportManager();
    $exporter->handleExportRequest($exportData, 'invoices_demo_' . date('Y-m-d'), null, [
        'title' => 'Invoices Report - ' . date('Y-m-d H:i'),
        'orientation' => 'L'  // Landscape for PDF
    ]);
    // Note: handleExportRequest() will exit() after download
}

// Fetch customers for filter dropdown
$customersStmt = $pdo->query("SELECT id, name FROM customers ORDER BY name LIMIT 250");
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = array_sum(array_column($invoices, 'total_usd'));
$totalPaid = array_sum(array_column($invoices, 'paid_usd'));
$totalBalance = $totalAmount - $totalPaid;

// ======================
// RENDER PAGE
// ======================
admin_render_layout_start([
    'title' => 'Admin · ' . $title,
    'heading' => $title,
    'subtitle' => 'Advanced filtering and export functionality demo',
    'active' => 'demo_filters',
    'user' => $user,
    'extra_head' => '<style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; transition: box-shadow 0.2s; }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .stat-label { font-size: 0.8rem; color: var(--muted); margin-bottom: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--text); }
        .stat-value.positive { color: #10b981; }
        .stat-value.negative { color: #ef4444; }

        /* Filter Form Styles */
        .filter-form { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .filter-form h3 { margin: 0 0 20px 0; font-size: 1.1rem; color: var(--text); display: flex; align-items: center; gap: 8px; }
        .filter-form h3:before { content: "🔍"; font-size: 1.2rem; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .filter-field { display: flex; flex-direction: column; }
        .filter-field label { font-size: 0.85rem; color: var(--muted); margin-bottom: 6px; font-weight: 600; }
        .filter-field input, .filter-field select { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; background: var(--bg-panel); color: var(--text); transition: all 0.2s; }
        .filter-field input:focus, .filter-field select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.1); }
        .filter-actions { display: flex; gap: 10px; }
        .btn-filter { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; }
        .btn-apply { background: var(--accent); color: white; }
        .btn-apply:hover { background: #1557c9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(31, 111, 235, 0.3); }
        .btn-clear { background: #f3f4f6; color: #6b7280; }
        .btn-clear:hover { background: #e5e7eb; }

        /* Export Section */
        .export-section { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .export-section h3 { margin: 0 0 16px 0; font-size: 1rem; color: var(--text); display: flex; align-items: center; gap: 8px; }
        .export-section h3:before { content: "📊"; font-size: 1.1rem; }
        .export-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .export-btn { padding: 10px 20px; border: 1px solid var(--border); border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; }
        .export-btn.csv { background: #10b981; color: white; border-color: #10b981; }
        .export-btn.csv:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .export-btn.excel { background: #059669; color: white; border-color: #059669; }
        .export-btn.excel:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3); }
        .export-btn.pdf { background: #ef4444; color: white; border-color: #ef4444; }
        .export-btn.pdf:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }

        /* Table Styles */
        .table-container { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f9fafb; }
        th, td { padding: 14px 16px; text-align: left; }
        th { color: var(--muted); font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--border); }
        td { color: var(--text); font-size: 0.9rem; border-bottom: 1px solid #f3f4f6; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: #f9fafb; }
        tbody tr:last-child td { border-bottom: none; }

        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.3px; }
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-issued { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-voided { background: #fee2e2; color: #991b1b; }

        .amount-positive { color: #10b981; font-weight: 600; }
        .amount-negative { color: #ef4444; font-weight: 600; }

        .no-results { text-align: center; padding: 60px 20px; color: var(--muted); font-size: 1rem; background: var(--bg-panel); border-radius: 12px; border: 1px solid var(--border); }
        .no-results p { margin: 0 0 8px 0; }
        .no-results a { color: var(--accent); text-decoration: underline; }

        .error-message { background: #fee2e2; color: #991b1b; padding: 16px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fca5a5; }

        .demo-notes { margin-top: 40px; padding: 24px; background: #eff6ff; border-radius: 12px; border-left: 4px solid var(--accent); }
        .demo-notes h3 { color: var(--accent); margin-bottom: 16px; font-size: 1.1rem; }
        .demo-notes ul { color: #4b5563; line-height: 2; padding-left: 24px; }
        .demo-notes ul li { margin-bottom: 8px; }
        .demo-notes strong { color: var(--text); }
        .demo-notes code { background: white; padding: 3px 8px; border-radius: 4px; font-family: monospace; color: var(--accent); border: 1px solid var(--border); }
    </style>'
]);
?>

<?php if (isset($error)): ?>
<div class="error-message">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- STATS GRID -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Results</div>
        <div class="stat-value"><?= count($invoices) ?></div>
    </div>

    <?php if ($filter->hasActiveFilters()): ?>
    <div class="stat-card">
        <div class="stat-label">Active Filters</div>
        <div class="stat-value"><?= $filter->getFilterCount() ?></div>
    </div>
    <?php endif; ?>

    <div class="stat-card">
        <div class="stat-label">Total Amount</div>
        <div class="stat-value">$<?= number_format($totalAmount, 0) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Paid</div>
        <div class="stat-value positive">$<?= number_format($totalPaid, 0) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value <?= $totalBalance > 0 ? 'negative' : 'positive' ?>">
            $<?= number_format($totalBalance, 0) ?>
        </div>
    </div>
</div>

<!-- FILTER FORM -->
<div class="filter-form">
    <h3>Filter Invoices</h3>
    <form method="get" action="">
        <div class="filter-grid">
            <div class="filter-field">
                <label for="search">Invoice #</label>
                <input type="text" id="search" name="search" placeholder="Search invoice..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>

            <div class="filter-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= ($_GET['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="issued" <?= ($_GET['status'] ?? '') === 'issued' ? 'selected' : '' ?>>Issued</option>
                    <option value="paid" <?= ($_GET['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="voided" <?= ($_GET['status'] ?? '') === 'voided' ? 'selected' : '' ?>>Voided</option>
                </select>
            </div>

            <div class="filter-field">
                <label for="customer">Customer</label>
                <select id="customer" name="customer">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $cust): ?>
                        <option value="<?= $cust['id'] ?>" <?= ($_GET['customer'] ?? '') == $cust['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cust['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="date_from">From Date</label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
            </div>

            <div class="filter-field">
                <label for="date_to">To Date</label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
            </div>

            <div class="filter-field">
                <label for="amount_min">Min Amount ($)</label>
                <input type="number" id="amount_min" name="amount_min" placeholder="0.00" step="0.01" value="<?= htmlspecialchars($_GET['amount_min'] ?? '') ?>">
            </div>

            <div class="filter-field">
                <label for="amount_max">Max Amount ($)</label>
                <input type="number" id="amount_max" name="amount_max" placeholder="0.00" step="0.01" value="<?= htmlspecialchars($_GET['amount_max'] ?? '') ?>">
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn-filter btn-apply">Apply Filters</button>
            <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>" class="btn-filter btn-clear" style="text-decoration: none; display: inline-flex; align-items: center;">Clear All</a>
        </div>
    </form>
</div>

<!-- EXPORT SECTION -->
<div class="export-section">
    <h3>Export Data</h3>
    <div class="export-buttons">
        <a href="?export=csv<?= !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="export-btn csv">
            📄 Export CSV
        </a>
        <a href="?export=excel<?= !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="export-btn excel">
            📊 Export Excel
        </a>
        <a href="?export=pdf<?= !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="export-btn pdf">
            📕 Export PDF
        </a>
    </div>
</div>

<!-- DATA TABLE -->
<?php if (empty($invoices)): ?>
<div class="no-results">
    <p><strong>No invoices found matching your criteria.</strong></p>
    <p>
        Try adjusting your filters or <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>">clear all filters</a> to see all results.
    </p>
</div>
<?php else: ?>
<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Order #</th>
                <th>Status</th>
                <th style="text-align: right;">Amount (USD)</th>
                <th style="text-align: right;">Paid (USD)</th>
                <th style="text-align: right;">Balance (USD)</th>
                <th>Created Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong></td>
                <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                <td><?= htmlspecialchars($invoice['order_number']) ?></td>
                <td>
                    <span class="status-badge status-<?= htmlspecialchars($invoice['status']) ?>">
                        <?= htmlspecialchars(strtoupper($invoice['status'])) ?>
                    </span>
                </td>
                <td style="text-align: right;"><strong>$<?= number_format($invoice['total_usd'], 2) ?></strong></td>
                <td style="text-align: right;" class="amount-positive">$<?= number_format($invoice['paid_usd'], 2) ?></td>
                <td style="text-align: right;" class="<?= $invoice['balance_usd'] > 0 ? 'amount-negative' : 'amount-positive' ?>">
                    $<?= number_format($invoice['balance_usd'], 2) ?>
                </td>
                <td><?= date('M d, Y', strtotime($invoice['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- DEMO NOTES -->
<div class="demo-notes">
    <h3>📋 Demo Notes</h3>
    <ul>
        <li><strong>Filter by Invoice #:</strong> Try searching for specific invoice numbers</li>
        <li><strong>Filter by Status:</strong> Select draft, issued, paid, or voided</li>
        <li><strong>Filter by Customer:</strong> Choose a specific customer</li>
        <li><strong>Date Range:</strong> Filter invoices created within a date range</li>
        <li><strong>Amount Range:</strong> Set minimum and maximum amounts</li>
        <li><strong>Export:</strong> Click CSV, Excel, or PDF to download filtered results</li>
        <li><strong>Clear Filters:</strong> Click the red Clear button or individual × badges</li>
    </ul>
    <p style="margin-top: 15px; color: var(--accent); font-weight: 600;">
        <strong>Integration Guide:</strong> See <code>FILTER_EXPORT_INTEGRATION_GUIDE.md</code> for step-by-step instructions on adding these features to other pages.
    </p>
</div>

<?php admin_render_layout_end(); ?>
