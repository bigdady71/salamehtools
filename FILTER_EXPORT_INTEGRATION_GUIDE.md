# Advanced Filtering & Export Integration Guide

## Overview

This guide shows how to integrate **advanced filtering** and **export functionality** (CSV, Excel, PDF) into existing admin pages.

**Created Components**:
1. `includes/FilterBuilder.php` - Universal filter builder
2. `includes/ExportManager.php` - Export handler (CSV, Excel, PDF)
3. `includes/export_buttons.php` - Reusable export UI

---

## Quick Start - 5 Steps to Add Filters & Export

### Step 1: Include Required Files

Add to the top of your page (after existing includes):

```php
require_once __DIR__ . '/../../includes/FilterBuilder.php';
require_once __DIR__ . '/../../includes/ExportManager.php';
require_once __DIR__ . '/../../includes/export_buttons.php';
```

### Step 2: Build Filters

Replace your existing WHERE clause with FilterBuilder:

```php
// Create filter builder
$filter = new FilterBuilder();

// Add filters based on URL parameters
$filter->addTextFilter('search', 'i.invoice_number');  // Text search
$filter->addSelectFilter('status', 'i.status', ['draft', 'issued', 'paid', 'voided']);  // Dropdown
$filter->addDateRangeFilter('date', 'i.created_at');  // Date range (date_from, date_to)
$filter->addSelectFilter('customer', 'o.customer_id');  // Customer filter
$filter->addNumericRangeFilter('amount', 'i.total_usd');  // Amount range (amount_min, amount_max)

// Build WHERE clause
$whereClause = $filter->buildWhereClause();  // Returns "WHERE ..." or empty string
$params = $filter->getParameters();  // Returns array of bound parameters
```

### Step 3: Update Your Query

Modify your existing SQL query:

```php
// BEFORE:
$stmt = $pdo->query("SELECT * FROM invoices LIMIT 100");

// AFTER:
$sql = "
    SELECT
        i.*,
        c.name as customer_name,
        o.order_number
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    {$whereClause}
    ORDER BY i.created_at DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Step 4: Handle Export Requests

Add export handler before any HTML output:

```php
// Handle export requests (must be before HTML output)
if (isset($_GET['export'])) {
    $exporter = new ExportManager();

    // Prepare data for export (remove HTML, format values)
    $exportData = ExportManager::prepareData($data, [
        'id' => 'Invoice ID',
        'invoice_number' => 'Invoice #',
        'customer_name' => 'Customer',
        'total_usd' => 'Amount (USD)',
        'status' => 'Status',
        'created_at' => 'Date'
    ]);

    // Handle export (exits after download)
    $exporter->handleExportRequest($exportData, 'invoices', null, [
        'title' => 'Invoices Report',
        'orientation' => 'L'  // Landscape for PDF
    ]);
}
```

### Step 5: Add Filter UI & Export Buttons

Add before your data table:

```php
<!-- Filter Form -->
<?php
$filterConfig = [
    ['name' => 'search', 'label' => 'Invoice #', 'type' => 'text'],
    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
        'draft' => 'Draft',
        'issued' => 'Issued',
        'paid' => 'Paid',
        'voided' => 'Voided'
    ]],
    ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date'],
    ['name' => 'date_to', 'label' => 'To Date', 'type' => 'date'],
    ['name' => 'amount_min', 'label' => 'Min Amount', 'type' => 'number'],
    ['name' => 'amount_max', 'label' => 'Max Amount', 'type' => 'number'],
];

echo $filter->renderFilterForm($filterConfig);
echo $filter->renderFilterBadges([
    'search' => 'Invoice #',
    'status' => 'Status',
    'date_from' => 'From',
    'date_to' => 'To',
    'amount_min' => 'Min Amount',
    'amount_max' => 'Max Amount'
]);
?>

<!-- Export Buttons -->
<?php echo renderExportButtons(); ?>

<!-- Your existing table -->
<table>...</table>
```

---

## Complete Example: Enhanced Invoices Page

Here's a complete working example:

```php
<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/FilterBuilder.php';
require_once __DIR__ . '/../../includes/ExportManager.php';
require_once __DIR__ . '/../../includes/export_buttons.php';

require_login();
$user = auth_user();
$pdo = db();

// ======================
// BUILD FILTERS
// ======================
$filter = new FilterBuilder();
$filter->addTextFilter('search', 'i.invoice_number');
$filter->addSelectFilter('status', 'i.status', ['draft', 'issued', 'paid', 'voided']);
$filter->addDateRangeFilter('date', 'i.created_at');
$filter->addSelectFilter('customer', 'o.customer_id');
$filter->addNumericRangeFilter('amount', 'i.total_usd');

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
        COALESCE(SUM(p.amount_usd), 0) as paid_usd,
        COALESCE(SUM(p.amount_lbp), 0) as paid_lbp
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN payments p ON p.invoice_id = i.id
    {$whereClause}
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ======================
// HANDLE EXPORT
// ======================
if (isset($_GET['export'])) {
    $exportData = ExportManager::prepareData($invoices, [
        'invoice_number' => 'Invoice #',
        'customer_name' => 'Customer',
        'order_number' => 'Order #',
        'status' => 'Status',
        'total_usd' => 'Amount (USD)',
        'paid_usd' => 'Paid (USD)',
        'created_at' => 'Created Date',
        'issued_at' => 'Issued Date'
    ]);

    $exporter = new ExportManager();
    $exporter->handleExportRequest($exportData, 'invoices_' . date('Y-m-d'), null, [
        'title' => 'Invoices Report - ' . date('Y-m-d'),
        'orientation' => 'L'
    ]);
}

// ======================
// RENDER PAGE
// ======================
$title = 'Admin · Invoices';
require_once __DIR__ . '/../../includes/admin_nav.php';
?>

<div style="padding: 20px;">
    <h1>Invoices</h1>

    <!-- Filter Form -->
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
            array_column($pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC), 'name', 'id')
        ],
        ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date'],
        ['name' => 'date_to', 'label' => 'To Date', 'type' => 'date'],
        ['name' => 'amount_min', 'label' => 'Min Amount', 'type' => 'number'],
        ['name' => 'amount_max', 'label' => 'Max Amount', 'type' => 'number'],
    ];

    echo $filter->renderFilterForm($filterConfig);
    echo $filter->renderFilterBadges([
        'search' => 'Invoice #',
        'status' => 'Status',
        'customer' => 'Customer',
        'date_from' => 'From',
        'date_to' => 'To',
        'amount_min' => 'Min Amount',
        'amount_max' => 'Max Amount'
    ]);
    ?>

    <!-- Export Buttons -->
    <?php echo renderExportButtons(); ?>

    <!-- Results Summary -->
    <p>Found <?= count($invoices) ?> invoice(s)</p>

    <!-- Data Table -->
    <table border="1" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Order #</th>
                <th>Status</th>
                <th>Amount (USD)</th>
                <th>Paid (USD)</th>
                <th>Balance (USD)</th>
                <th>Created Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                <td><?= htmlspecialchars($invoice['order_number']) ?></td>
                <td><?= htmlspecialchars($invoice['status']) ?></td>
                <td>$<?= number_format($invoice['total_usd'], 2) ?></td>
                <td>$<?= number_format($invoice['paid_usd'], 2) ?></td>
                <td>$<?= number_format($invoice['total_usd'] - $invoice['paid_usd'], 2) ?></td>
                <td><?= date('Y-m-d', strtotime($invoice['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
```

---

## Filter Types Reference

### 1. Text Search Filter
```php
$filter->addTextFilter('search', 'column_name');
// URL: ?search=value
// SQL: WHERE column_name LIKE '%value%'
```

### 2. Exact Match Filter
```php
$filter->addExactFilter('id', 'column_name');
// URL: ?id=123
// SQL: WHERE column_name = '123'
```

### 3. Select/Dropdown Filter
```php
$filter->addSelectFilter('status', 'column_name', ['draft', 'issued', 'paid']);
// URL: ?status=issued
// SQL: WHERE column_name = 'issued'
// Validates against allowed values list
```

### 4. Date Range Filter
```php
$filter->addDateRangeFilter('date', 'column_name');
// URL: ?date_from=2024-01-01&date_to=2024-01-31
// SQL: WHERE column_name >= '2024-01-01 00:00:00' AND column_name <= '2024-01-31 23:59:59'
```

### 5. Numeric Range Filter
```php
$filter->addNumericRangeFilter('amount', 'column_name');
// URL: ?amount_min=100&amount_max=1000
// SQL: WHERE column_name >= 100 AND column_name <= 1000
```

### 6. Boolean Filter
```php
$filter->addBooleanFilter('active', 'is_active');
// URL: ?active=1 or ?active=0
// SQL: WHERE is_active = 1 (or 0)
```

### 7. Multi-Select Filter
```php
$filter->addMultiSelectFilter('categories', 'category_id', [1, 2, 3]);
// URL: ?categories[]=1&categories[]=2
// SQL: WHERE category_id IN (1, 2)
```

### 8. Custom Filter
```php
$filter->addCustomFilter('(column1 > :val1 OR column2 < :val2)', [':val1' => 100, ':val2' => 50]);
// For complex conditions not covered by standard filters
```

---

## Export Formats

### CSV Export
- **Lightweight**: Small file size, fast generation
- **Excel Compatible**: UTF-8 BOM included
- **Use Case**: Data import/export, spreadsheet analysis

### Excel Export (XLSX)
- **Rich Formatting**: Bold headers, auto-column width
- **Auto-Filter**: Sortable columns
- **Requires**: PhpSpreadsheet library (optional, falls back to CSV)
- **Use Case**: Professional reports, formatted data

### PDF Export
- **Print-Ready**: Professional layout
- **Portable**: Universal format
- **Requires**: TCPDF library (optional, falls back to CSV)
- **Options**: Landscape/Portrait orientation
- **Use Case**: Formal reports, archiving

---

## Page-Specific Integration Examples

### Orders Page
```php
$filter = new FilterBuilder();
$filter->addTextFilter('search', 'o.order_number');
$filter->addSelectFilter('customer', 'o.customer_id');
$filter->addDateRangeFilter('date', 'o.created_at');
$filter->addSelectFilter('status', 'ose.status', ['on_hold', 'approved', 'preparing', 'ready', 'in_transit', 'delivered']);
$filter->addSelectFilter('sales_rep', 'o.sales_rep_id');
$filter->addBooleanFilter('invoice_ready', 'o.invoice_ready');
```

### Products Page
```php
$filter = new FilterBuilder();
$filter->addTextFilter('search', 'p.item_name');
$filter->addTextFilter('sku', 'p.sku');
$filter->addBooleanFilter('active', 'p.is_active');
$filter->addNumericRangeFilter('price', 'p.price_per_unit_usd');
$filter->addNumericRangeFilter('stock', 'p.quantity_available');
```

### Customers Page
```php
$filter = new FilterBuilder();
$filter->addTextFilter('search', 'c.name');
$filter->addTextFilter('email', 'c.email');
$filter->addSelectFilter('sales_rep', 'c.assigned_sales_rep_id');
$filter->addDateRangeFilter('created', 'c.created_at');
```

### Warehouse Page
```php
$filter = new FilterBuilder();
$filter->addTextFilter('product', 'p.item_name');
$filter->addSelectFilter('movement_type', 'wm.movement_type', ['in', 'out', 'adjustment']);
$filter->addDateRangeFilter('date', 'wm.created_at');
$filter->addTextFilter('ref', 'wm.reference_number');
```

### Receivables Page
```php
$filter = new FilterBuilder();
$filter->addTextFilter('customer', 'c.name');
$filter->addSelectFilter('aging', 'custom_aging_calculation');  // Use CASE WHEN in query
$filter->addNumericRangeFilter('balance', 'outstanding_balance');
$filter->addSelectFilter('sales_rep', 'c.assigned_sales_rep_id');
```

---

## Advanced Features

### Filter Persistence
Filters are persisted via URL parameters, so:
- Bookmarkable filtered views
- Shareable filtered reports
- Browser back/forward works correctly

### Filter Badges
Display active filters as removable badges:
```php
echo $filter->renderFilterBadges([
    'search' => 'Search Term',
    'status' => 'Status'
]);
```

Output: `[Search Term: invoice-123 ×] [Status: paid ×] Clear All Filters`

### Export with Current Filters
Export buttons automatically include current filter parameters, so exported data matches the filtered view.

### SQL Injection Protection
All filters use prepared statements with parameter binding - 100% safe from SQL injection.

---

## Installation Requirements

### Core (No Dependencies)
- CSV export works out of the box
- All filters work out of the box

### Optional Libraries
Install via Composer for enhanced features:

```bash
# For Excel export
composer require phpoffice/phpspreadsheet

# For PDF export
composer require tecnickcom/tcpdf
```

If these libraries are not installed, exports automatically fall back to CSV.

---

## Performance Considerations

### Indexing
Ensure filtered columns have indexes:
```sql
CREATE INDEX idx_invoices_status ON invoices(status);
CREATE INDEX idx_invoices_created_at ON invoices(created_at);
CREATE INDEX idx_orders_customer_id ON orders(customer_id);
```

Already applied in Week 2 Performance Optimization!

### Pagination
For large datasets, combine with Paginator:
```php
require_once __DIR__ . '/../../includes/Paginator.php';

$paginator = new Paginator($pdo);
$result = $paginator->paginate($sql, $params, Paginator::getCurrentPage(), 50);
$data = $result['data'];

// Render pagination links
echo $paginator->renderLinks($result, $_SERVER['PHP_SELF'], $_GET);
```

### Export Limits
For very large exports (10,000+ rows), consider:
1. Adding a confirmation step
2. Background job processing
3. Chunked downloads
4. Email delivery

---

## Testing Checklist

### Filters
- [ ] Text search works (partial match)
- [ ] Date range filters both from/to
- [ ] Select filters validate against whitelist
- [ ] Multiple filters combine with AND
- [ ] Filter badges display correctly
- [ ] Clear filter link works
- [ ] Clear all filters resets page

### Exports
- [ ] CSV downloads with correct filename
- [ ] CSV opens in Excel correctly (UTF-8)
- [ ] Excel export has bold headers
- [ ] Excel auto-filter works
- [ ] PDF has proper layout
- [ ] PDF landscape orientation for wide tables
- [ ] Export includes only filtered data
- [ ] Export handles empty results

### Security
- [ ] SQL injection attempts blocked
- [ ] Invalid filter values ignored
- [ ] XSS attempts in exports escaped
- [ ] Large exports don't timeout

---

## Troubleshooting

### "Headers already sent" error
**Cause**: HTML output before export
**Fix**: Move export handler before ANY echo/HTML

### Excel export downloads as .zip
**Cause**: PhpSpreadsheet not installed
**Fix**: Install via Composer or use CSV fallback

### PDF formatting issues
**Cause**: Long text overflow
**Fix**: Adjust column widths in ExportManager::exportToPDF()

### Filters not working
**Cause**: Missing parameter binding
**Fix**: Always use `$stmt->execute($params)` with `$filter->getParameters()`

---

## Next Steps

1. **Apply to Invoices page** - Simplest page to start with
2. **Apply to Orders page** - More complex with status tracking
3. **Apply to Products page** - Add inventory filters
4. **Apply to Customers page** - Add customer segments
5. **Apply to Warehouse page** - Add movement type filters
6. **Apply to Receivables page** - Add aging bucket filters

Each page follows the same 5-step pattern!

---

## Support

For issues or questions:
1. Check this guide first
2. Review FilterBuilder.php comments
3. Review ExportManager.php comments
4. Test with simple example before complex pages
