<?php
/**
 * DEMO PAGE: Advanced Filters & Export
 *
 * This demo page shows how to integrate FilterBuilder and ExportManager
 * Use this as a reference when updating other admin pages
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
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
$title = 'Admin · Filters & Export Demo';

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

// ======================
// RENDER PAGE
// ======================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0a0a0a;
            color: #ffffff;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        h1 {
            color: #00ff88;
            margin-bottom: 10px;
            font-size: 2rem;
        }

        .subtitle {
            color: #888;
            margin-bottom: 30px;
            font-size: 1rem;
        }

        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px 20px;
            min-width: 150px;
        }

        .stat-label {
            color: #888;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }

        .stat-value {
            color: #00ff88;
            font-size: 1.5rem;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #1a1a1a;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 20px;
        }

        thead {
            background: #2a2a2a;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #333;
        }

        th {
            color: #00ff88;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tbody tr:hover {
            background: #252525;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-draft { background: #fef3c7; color: #92400e; }
        .status-issued { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-voided { background: #fee2e2; color: #991b1b; }

        .amount-positive {
            color: #00ff88;
        }

        .amount-negative {
            color: #ff6b6b;
        }

        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #888;
            font-size: 1.1rem;
        }

        .error-message {
            background: #ff6b6b;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .back-link {
            display: inline-block;
            color: #00ff88;
            text-decoration: none;
            margin-bottom: 20px;
            padding: 8px 16px;
            background: #1a1a1a;
            border-radius: 4px;
            border: 1px solid #333;
        }

        .back-link:hover {
            background: #252525;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="subtitle">
            This demo page shows advanced filtering and export functionality.<br>
            Use this as a reference when integrating filters into other admin pages.
        </p>

        <?php if (isset($error)): ?>
        <div class="error-message">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- STATS BAR -->
        <div class="stats-bar">
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

            <?php
            $totalAmount = array_sum(array_column($invoices, 'total_usd'));
            $totalPaid = array_sum(array_column($invoices, 'paid_usd'));
            $totalBalance = $totalAmount - $totalPaid;
            ?>

            <div class="stat-card">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">$<?= number_format($totalAmount, 0) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value">$<?= number_format($totalPaid, 0) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value <?= $totalBalance > 0 ? 'amount-negative' : '' ?>">
                    $<?= number_format($totalBalance, 0) ?>
                </div>
            </div>
        </div>

        <!-- FILTER FORM -->
        <?php
        $filterConfig = [
            ['name' => 'search', 'label' => 'Invoice #', 'type' => 'text'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                'draft' => 'Draft',
                'issued' => 'Issued',
                'paid' => 'Paid',
                'voided' => 'Voided'
            ]],
            ['name' => 'customer', 'label' => 'Customer', 'type' => 'select', 'options' =>
                array_combine(
                    array_column($customers, 'id'),
                    array_column($customers, 'name')
                )
            ],
            ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date'],
            ['name' => 'date_to', 'label' => 'To Date', 'type' => 'date'],
            ['name' => 'amount_min', 'label' => 'Min Amount ($)', 'type' => 'number'],
            ['name' => 'amount_max', 'label' => 'Max Amount ($)', 'type' => 'number'],
        ];

        echo $filter->renderFilterForm($filterConfig);
        ?>

        <!-- FILTER BADGES -->
        <?php
        echo $filter->renderFilterBadges([
            'search' => 'Invoice #',
            'status' => 'Status',
            'customer' => 'Customer',
            'date_from' => 'From Date',
            'date_to' => 'To Date',
            'amount_min' => 'Min Amount',
            'amount_max' => 'Max Amount'
        ]);
        ?>

        <!-- EXPORT BUTTONS -->
        <?php echo renderExportButtons(); ?>

        <!-- DATA TABLE -->
        <?php if (empty($invoices)): ?>
        <div class="no-results">
            <p>No invoices found matching your criteria.</p>
            <p style="margin-top: 10px; font-size: 0.9rem;">
                Try adjusting your filters or <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>" style="color: #00ff88;">clear all filters</a>.
            </p>
        </div>
        <?php else: ?>
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
                            <?= htmlspecialchars(ucfirst($invoice['status'])) ?>
                        </span>
                    </td>
                    <td style="text-align: right;">$<?= number_format($invoice['total_usd'], 2) ?></td>
                    <td style="text-align: right;" class="amount-positive">$<?= number_format($invoice['paid_usd'], 2) ?></td>
                    <td style="text-align: right;" class="<?= $invoice['balance_usd'] > 0 ? 'amount-negative' : 'amount-positive' ?>">
                        $<?= number_format($invoice['balance_usd'], 2) ?>
                    </td>
                    <td><?= date('M d, Y', strtotime($invoice['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- DEMO NOTES -->
        <div style="margin-top: 40px; padding: 20px; background: #1a1a1a; border-radius: 8px; border-left: 4px solid #00ff88;">
            <h3 style="color: #00ff88; margin-bottom: 10px;">Demo Notes</h3>
            <ul style="color: #888; line-height: 2;">
                <li><strong>Filter by Invoice #:</strong> Try searching for specific invoice numbers</li>
                <li><strong>Filter by Status:</strong> Select draft, issued, paid, or voided</li>
                <li><strong>Filter by Customer:</strong> Choose a specific customer</li>
                <li><strong>Date Range:</strong> Filter invoices created within a date range</li>
                <li><strong>Amount Range:</strong> Set minimum and maximum amounts</li>
                <li><strong>Export:</strong> Click CSV, Excel, or PDF to download filtered results</li>
                <li><strong>Clear Filters:</strong> Click the red Clear button or individual ×  badges</li>
            </ul>
            <p style="margin-top: 15px; color: #00ff88;">
                <strong>Integration Guide:</strong> See <code>FILTER_EXPORT_INTEGRATION_GUIDE.md</code> for step-by-step instructions on adding these features to other pages.
            </p>
        </div>
    </div>
</body>
</html>
