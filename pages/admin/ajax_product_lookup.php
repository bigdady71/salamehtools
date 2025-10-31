<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['results' => [], 'error' => 'Forbidden']);
    exit;
}

$term = trim((string)($_GET['term'] ?? ''));
header('Content-Type: application/json; charset=utf-8');

if ($term === '') {
    echo json_encode(['results' => []]);
    exit;
}

$pdo = db();
$likeTerm = '%' . $term . '%';
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.item_name,
        p.sku,
        p.unit,
        p.sale_price_usd,
        p.wholesale_price_usd,
        p.quantity_on_hand
    FROM products p
    WHERE p.is_active = 1
      AND (p.item_name LIKE :term OR p.sku LIKE :term OR p.code_clean LIKE :term)
    ORDER BY p.item_name ASC
    LIMIT 20
");
$stmt->execute([':term' => $likeTerm]);

$results = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $results[] = [
        'id' => (int)$row['id'],
        'name' => $row['item_name'],
        'sku' => $row['sku'],
        'unit' => $row['unit'],
        'sale_price_usd' => isset($row['sale_price_usd']) ? (float)$row['sale_price_usd'] : null,
        'wholesale_price_usd' => isset($row['wholesale_price_usd']) ? (float)$row['wholesale_price_usd'] : null,
        'quantity_on_hand' => isset($row['quantity_on_hand']) ? (float)$row['quantity_on_hand'] : null,
    ];
}

echo json_encode(['results' => $results]);
