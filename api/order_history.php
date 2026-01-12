<?php
/**
 * Order History API Endpoint
 *
 * Returns order action history and stock movements for a given order.
 * Used by both warehouse and sales portal to view order audit trail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/OrderLifecycle.php';

header('Content-Type: application/json');

// Require authentication
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = auth_user();
$pdo = db();

// Get order ID from request
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

// Verify user has access to this order
$accessCheck = $pdo->prepare("
    SELECT id FROM orders
    WHERE id = :order_id
    AND (
        sales_rep_id = :user_id
        OR :role IN ('warehouse', 'admin')
    )
");
$accessCheck->execute([
    'order_id' => $orderId,
    'user_id' => $user['id'],
    'role' => $user['role']
]);

if (!$accessCheck->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied to this order']);
    exit;
}

$lifecycle = new OrderLifecycle($pdo);

// Get action history
$history = $lifecycle->getOrderHistory($orderId);

// Get stock movements
$movements = $lifecycle->getOrderMovements($orderId);

// Format response
$response = [
    'order_id' => $orderId,
    'history' => array_map(function($action) {
        return [
            'id' => $action['id'],
            'action' => $action['action_type'],
            'from_status' => $action['previous_status'],
            'to_status' => $action['new_status'],
            'performed_by' => $action['performed_by_name'] ?? 'System',
            'role' => $action['performed_by_role'],
            'reason' => $action['reason'],
            'notes' => $action['notes'],
            'timestamp' => $action['created_at'],
            'formatted_time' => date('M d, Y H:i', strtotime($action['created_at']))
        ];
    }, $history),
    'movements' => array_map(function($move) {
        return [
            'id' => $move['id'],
            'type' => $move['movement_type'],
            'product' => $move['product_name'],
            'quantity' => (float)$move['quantity'],
            'from' => $move['from_location_type'],
            'to' => $move['to_location_type'],
            'warehouse_before' => (float)$move['warehouse_stock_before'],
            'warehouse_after' => (float)$move['warehouse_stock_after'],
            'sales_rep_before' => (float)$move['sales_rep_stock_before'],
            'sales_rep_after' => (float)$move['sales_rep_stock_after'],
            'performed_by' => $move['performed_by_name'] ?? 'System',
            'timestamp' => $move['created_at'],
            'formatted_time' => date('M d, Y H:i', strtotime($move['created_at']))
        ];
    }, $movements)
];

echo json_encode($response);
