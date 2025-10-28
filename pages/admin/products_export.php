<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();

// Get filter parameters from query string
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$activeFilter = $_GET['active'] ?? '';

// Build WHERE conditions (same as products.php)
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR p.second_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($category !== '') {
    $where[] = "(p.topcat_name = :category OR p.midcat_name = :category)";
    $params[':category'] = $category;
}

if ($stockFilter === 'low') {
    $where[] = "p.quantity_on_hand <= GREATEST(p.min_quantity, 5)";
} elseif ($stockFilter === 'gt1') {
    $where[] = "p.quantity_on_hand > 1";
} elseif ($stockFilter === 'out') {
    $where[] = "p.quantity_on_hand = 0";
}

if ($activeFilter !== '') {
    $where[] = "p.is_active = :active";
    $params[':active'] = (int)$activeFilter;
}

$whereClause = implode(' AND ', $where);

// Query all products with current filters
$stmt = $pdo->prepare("
    SELECT 
        p.sku AS 'SKU',
        p.item_name AS 'Product Name',
        p.second_name AS 'Secondary Name',
        p.type AS 'Type',
        p.unit AS 'Unit',
        p.topcat_name AS 'Top Category',
        p.midcat_name AS 'Sub Category',
        p.sale_price_usd AS 'Retail Price (USD)',
        p.wholesale_price_usd AS 'Wholesale Price (USD)',
        p.minqty_disc1 AS 'Wholesale Price 1',
        p.minqty_disc2 AS 'Wholesale Price 2',
        p.min_quantity AS 'Min Quantity',
        p.quantity_on_hand AS 'Stock On Hand',
        p.description AS 'Description',
        CASE WHEN p.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS 'Status',
        p.created_at AS 'Created Date',
        p.updated_at AS 'Last Updated'
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.item_name ASC
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="products_export_' . date('Y-m-d_His') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Add UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

// Open output stream
$output = fopen('php://output', 'w');

// Get column headers from the first row
$firstRow = $stmt->fetch(PDO::FETCH_ASSOC);
if ($firstRow) {
    // Write headers
    fputcsv($output, array_keys($firstRow));
    
    // Write first row
    fputcsv($output, $firstRow);
    
    // Write remaining rows
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
} else {
    // No data - write headers only
    fputcsv($output, ['No products found with the current filters']);
}

fclose($output);
exit;