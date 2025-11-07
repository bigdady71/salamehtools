<?php

namespace SalamehTools\Services;

use PDO;
use PDOException;

/**
 * Order Service
 *
 * Handles all business logic related to orders:
 * - Order creation and updates
 * - Invoice readiness evaluation
 * - Status management
 * - Invoice synchronization
 *
 * Extracted from pages/admin/orders.php for better testability and reusability
 */
class OrderService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $pdo;
        $this->pdo = $pdo;
    }

    /**
     * Evaluate if an order is ready to be invoiced
     *
     * Checks 8 rules:
     * 1. Order has at least 1 item
     * 2. Customer assigned
     * 3. Sales rep assigned
     * 4. Total > 0
     * 5. Exchange rate set (for multi-currency)
     * 6. No items with quantity ≤ 0
     * 7. No items with price ≤ 0
     * 8. No items with missing product reference
     *
     * @param int $orderId
     * @return array ['ready' => bool, 'reasons' => array]
     */
    public function evaluateInvoiceReady(int $orderId): array
    {
        $reasons = [];

        // Fetch order details
        $orderStmt = $this->pdo->prepare("
            SELECT customer_id, sales_rep_id, total_usd, total_lbp, exchange_rate_id
            FROM orders
            WHERE id = :id
        ");
        $orderStmt->execute([':id' => $orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['ready' => false, 'reasons' => ['order_not_found']];
        }

        // Rule 2: Customer assigned
        if (empty($order['customer_id'])) {
            $reasons[] = 'no_customer';
        }

        // Rule 3: Sales rep assigned
        if (empty($order['sales_rep_id'])) {
            $reasons[] = 'no_sales_rep';
        }

        // Rule 4: Total > 0
        $totalUsd = (float)($order['total_usd'] ?? 0);
        $totalLbp = (float)($order['total_lbp'] ?? 0);
        if ($totalUsd <= 0 && $totalLbp <= 0) {
            $reasons[] = 'total_zero_or_negative';
        }

        // Rule 5: Exchange rate set (if multi-currency)
        if ($totalUsd > 0 && $totalLbp > 0 && empty($order['exchange_rate_id'])) {
            $reasons[] = 'missing_exchange_rate';
        }

        // Fetch order items
        $itemsStmt = $this->pdo->prepare("
            SELECT id, product_id, quantity, unit_price_usd, unit_price_lbp
            FROM order_items
            WHERE order_id = :order_id
        ");
        $itemsStmt->execute([':order_id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Rule 1: At least 1 item
        if (count($items) === 0) {
            $reasons[] = 'no_items';
        }

        // Rules 6, 7, 8: Item validation
        foreach ($items as $item) {
            // Rule 8: Missing product
            if (empty($item['product_id'])) {
                $reasons[] = 'item_missing_product';
                break;
            }

            // Rule 6: Quantity > 0
            if ((float)$item['quantity'] <= 0) {
                $reasons[] = 'item_quantity_zero_or_negative';
                break;
            }

            // Rule 7: Price > 0
            $priceUsd = (float)($item['unit_price_usd'] ?? 0);
            $priceLbp = (float)($item['unit_price_lbp'] ?? 0);
            if ($priceUsd <= 0 && $priceLbp <= 0) {
                $reasons[] = 'item_price_zero_or_negative';
                break;
            }
        }

        $ready = count($reasons) === 0;

        return ['ready' => $ready, 'reasons' => $reasons];
    }

    /**
     * Check if orders table has invoice_ready column
     *
     * @return bool
     */
    public function hasInvoiceReadyColumn(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM orders LIKE 'invoice_ready'");
            return (bool)$stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Refresh invoice_ready flag for an order
     *
     * @param int $orderId
     * @return array ['success' => bool, 'ready' => bool, 'reasons' => array, 'message' => string]
     */
    public function refreshInvoiceReady(int $orderId): array
    {
        if (!$this->hasInvoiceReadyColumn()) {
            return [
                'success' => false,
                'ready' => false,
                'reasons' => ['column_missing'],
                'message' => 'invoice_ready column does not exist'
            ];
        }

        $evaluation = $this->evaluateInvoiceReady($orderId);
        $ready = $evaluation['ready'] ? 1 : 0;

        try {
            $stmt = $this->pdo->prepare("UPDATE orders SET invoice_ready = :ready WHERE id = :id");
            $stmt->execute([':ready' => $ready, ':id' => $orderId]);

            return [
                'success' => true,
                'ready' => (bool)$ready,
                'reasons' => $evaluation['reasons'],
                'message' => $ready ? 'Order is ready for invoicing' : 'Order is not ready for invoicing'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'ready' => false,
                'reasons' => ['update_failed'],
                'message' => 'Failed to update invoice_ready flag: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Sync order to invoice (create or update invoice)
     *
     * @param int $orderId
     * @param int $actorUserId User performing the action
     * @return array ['success' => bool, 'invoice_id' => int|null, 'message' => string]
     */
    public function syncOrderInvoice(int $orderId, int $actorUserId): array
    {
        try {
            $this->pdo->beginTransaction();

            // Fetch order
            $orderStmt = $this->pdo->prepare("
                SELECT id, order_number, customer_id, sales_rep_id, total_usd, total_lbp, exchange_rate_id
                FROM orders
                WHERE id = :id
            ");
            $orderStmt->execute([':id' => $orderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $this->pdo->rollBack();
                return ['success' => false, 'invoice_id' => null, 'message' => 'Order not found'];
            }

            // Check if invoice already exists
            $invoiceStmt = $this->pdo->prepare("SELECT id FROM invoices WHERE order_id = :order_id LIMIT 1");
            $invoiceStmt->execute([':order_id' => $orderId]);
            $existingInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingInvoice) {
                // Update existing invoice
                $invoiceId = (int)$existingInvoice['id'];
                $updateStmt = $this->pdo->prepare("
                    UPDATE invoices
                    SET total_usd = :total_usd,
                        total_lbp = :total_lbp,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':total_usd' => $order['total_usd'],
                    ':total_lbp' => $order['total_lbp'],
                    ':id' => $invoiceId
                ]);

                // Delete and recreate invoice items
                $this->pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = :id")->execute([':id' => $invoiceId]);
            } else {
                // Create new invoice
                $invoiceNumber = 'INV-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);

                $insertStmt = $this->pdo->prepare("
                    INSERT INTO invoices (invoice_number, order_id, sales_rep_id, status, total_usd, total_lbp, created_at, updated_at)
                    VALUES (:invoice_number, :order_id, :sales_rep_id, 'draft', :total_usd, :total_lbp, NOW(), NOW())
                ");
                $insertStmt->execute([
                    ':invoice_number' => $invoiceNumber,
                    ':order_id' => $orderId,
                    ':sales_rep_id' => $order['sales_rep_id'],
                    ':total_usd' => $order['total_usd'],
                    ':total_lbp' => $order['total_lbp']
                ]);

                $invoiceId = (int)$this->pdo->lastInsertId();
            }

            // Copy order items to invoice items
            $orderItems = $this->pdo->prepare("
                SELECT product_id, quantity, unit_price_usd, unit_price_lbp, discount_percent
                FROM order_items
                WHERE order_id = :order_id
            ");
            $orderItems->execute([':order_id' => $orderId]);

            $insertItemStmt = $this->pdo->prepare("
                INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price_usd, unit_price_lbp, discount_percent)
                VALUES (:invoice_id, :product_id, :quantity, :unit_price_usd, :unit_price_lbp, :discount_percent)
            ");

            foreach ($orderItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $insertItemStmt->execute([
                    ':invoice_id' => $invoiceId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit_price_usd' => $item['unit_price_usd'],
                    ':unit_price_lbp' => $item['unit_price_lbp'],
                    ':discount_percent' => $item['discount_percent'] ?? 0
                ]);
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'message' => $existingInvoice ? 'Invoice updated successfully' : 'Invoice created successfully'
            ];

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'invoice_id' => null,
                'message' => 'Failed to sync invoice: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Auto-promote order to invoice if ready (when status changes)
     *
     * @param int $orderId
     * @param string $newStatus New order status
     * @param int $actorUserId User performing the action
     * @return array|null Invoice result if promoted, null otherwise
     */
    public function autoPromoteInvoiceIfReady(int $orderId, string $newStatus, int $actorUserId): ?array
    {
        // Only auto-promote on certain statuses
        $autoPromoteStatuses = ['confirmed', 'ready_to_ship', 'shipped'];

        if (!in_array($newStatus, $autoPromoteStatuses, true)) {
            return null;
        }

        // Check if order is invoice-ready
        $evaluation = $this->evaluateInvoiceReady($orderId);

        if (!$evaluation['ready']) {
            return null;
        }

        // Check if invoice already exists
        $stmt = $this->pdo->prepare("SELECT id FROM invoices WHERE order_id = :order_id LIMIT 1");
        $stmt->execute([':order_id' => $orderId]);

        if ($stmt->fetch()) {
            return null; // Invoice already exists
        }

        // Create invoice
        return $this->syncOrderInvoice($orderId, $actorUserId);
    }

    /**
     * Describe invoice readiness reasons in human-readable format
     *
     * @param array $reasons Array of reason codes
     * @return string
     */
    public function describeInvoiceReasons(array $reasons): string
    {
        if (empty($reasons)) {
            return 'Order is ready for invoicing';
        }

        $messages = [
            'order_not_found' => 'Order not found',
            'no_customer' => 'Customer not assigned',
            'no_sales_rep' => 'Sales representative not assigned',
            'total_zero_or_negative' => 'Order total is zero or negative',
            'missing_exchange_rate' => 'Exchange rate not set for multi-currency order',
            'no_items' => 'Order has no line items',
            'item_missing_product' => 'One or more items missing product reference',
            'item_quantity_zero_or_negative' => 'One or more items have zero or negative quantity',
            'item_price_zero_or_negative' => 'One or more items have zero or negative price',
            'column_missing' => 'Database column invoice_ready does not exist',
            'update_failed' => 'Failed to update invoice readiness flag'
        ];

        $descriptions = [];
        foreach ($reasons as $reason) {
            $descriptions[] = $messages[$reason] ?? $reason;
        }

        return implode('; ', $descriptions);
    }

    /**
     * Fetch order summary with all details
     *
     * @param int $orderId
     * @return array|null Order data with customer, sales rep, items
     */
    public function fetchOrderSummary(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                o.*,
                c.name as customer_name,
                u.name as sales_rep_name,
                er.rate as exchange_rate
            FROM orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN users u ON u.id = o.sales_rep_id
            LEFT JOIN exchange_rates er ON er.id = o.exchange_rate_id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        // Fetch items
        $itemsStmt = $this->pdo->prepare("
            SELECT
                oi.*,
                p.sku,
                p.item_name as product_name
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = :order_id
        ");
        $itemsStmt->execute([':order_id' => $orderId]);
        $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }
}
