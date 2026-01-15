<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/guard.php';

header('Content-Type: application/json');

// Require login
require_login();
$user = auth_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$returnId = (int)($_GET['return_id'] ?? 0);

if ($returnId <= 0) {
    echo json_encode(['items' => []]);
    exit;
}

try {
    $pdo = db();

    // Verify access - admin can see all, sales rep can only see their own
    $accessWhere = '';
    $params = [':return_id' => $returnId];

    if ($user['role'] !== 'admin') {
        $accessWhere = ' AND cr.sales_rep_id = :rep_id';
        $params[':rep_id'] = (int)$user['id'];
    }

    $stmt = $pdo->prepare("
        SELECT
            cri.*,
            p.sku,
            p.item_name
        FROM customer_return_items cri
        JOIN customer_returns cr ON cr.id = cri.return_id
        JOIN products p ON p.id = cri.product_id
        WHERE cri.return_id = :return_id {$accessWhere}
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['items' => $items]);

} catch (Exception $e) {
    error_log("Get return items error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
