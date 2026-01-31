<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden - Sales representatives only']);
    exit;
}

$pdo = db();
$repId = (int)$user['id'];
$orderId = (int)($_GET['order_id'] ?? 0);

header('Content-Type: application/json');

if ($orderId <= 0) {
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

try {
    // Get order details
    $orderStmt = $pdo->prepare("
        SELECT
            o.id,
            o.order_number,
            o.order_type,
            o.status,
            o.total_usd,
            o.total_lbp,
            o.created_at,
            o.notes,
            c.name as customer_name,
            c.phone as customer_phone,
            c.location as customer_location
        FROM orders o
        INNER JOIN customers c ON c.id = o.customer_id
        WHERE o.id = :order_id AND c.assigned_sales_rep_id = :rep_id
    ");
    $orderStmt->execute([':order_id' => $orderId, ':rep_id' => $repId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['error' => 'Order not found or you do not have permission to view this order']);
        exit;
    }

    // Get order items
    $itemsStmt = $pdo->prepare("
        SELECT
            oi.quantity,
            oi.unit_price_usd,
            oi.unit_price_lbp,
            p.sku,
            p.item_name as product_name
        FROM order_items oi
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = :order_id
        ORDER BY p.item_name ASC
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Status labels
    $statusLabels = [
        'on_hold' => 'On Hold',
        'approved' => 'Approved',
        'preparing' => 'Preparing',
        'ready' => 'Ready for Pickup',
        'in_transit' => 'In Transit',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    // Check for OTP if order is ready
    $otpData = null;
    if ($order['status'] === 'ready') {
        $otpStmt = $pdo->prepare("
            SELECT * FROM order_transfer_otps
            WHERE order_id = ? AND expires_at > NOW()
        ");
        $otpStmt->execute([$orderId]);
        $otpData = $otpStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Prepare response
    $response = [
        'id' => $order['id'],
        'order_number' => $order['order_number'] ?? 'Order #' . $order['id'],
        'order_type' => $order['order_type'],
        'status' => $order['status'],
        'status_label' => $statusLabels[$order['status']] ?? ucfirst($order['status']),
        'total_usd' => $order['total_usd'],
        'total_lbp' => $order['total_lbp'],
        'created_at' => date('M d, Y H:i', strtotime($order['created_at'])),
        'notes' => $order['notes'],
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'],
        'customer_location' => $order['customer_location'],
        'items' => $items,
        'otp_data' => $otpData ? [
            'sales_rep_otp' => $otpData['sales_rep_otp'],
            'warehouse_verified' => $otpData['warehouse_verified_at'] !== null,
            'sales_rep_verified' => $otpData['sales_rep_verified_at'] !== null,
            'both_verified' => ($otpData['warehouse_verified_at'] !== null && $otpData['sales_rep_verified_at'] !== null)
        ] : null
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
