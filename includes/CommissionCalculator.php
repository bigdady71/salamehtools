<?php

declare(strict_types=1);

/**
 * Commission Calculator for Sales Representatives
 *
 * Commission Rules:
 * 1. Direct Sale (4%): When a sales rep makes a sale (orders.sales_rep_id is set), that rep earns 4%
 * 2. Customer Self-Order (4%): When a customer places their own order (no orders.sales_rep_id),
 *    the customer's assigned rep (customers.assigned_sales_rep_id) earns 4%
 * 3. Cross-Rep Sale: If Rep A sells to a customer assigned to Rep B, only Rep A gets the 4% (direct seller wins)
 *
 * Only ONE rep earns commission per order.
 */
class CommissionCalculator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get the commission rate for a given sales rep and type
     */
    public function getRate(int $salesRepId, string $type, string $date): float
    {
        // Check for rep-specific rate first, then fall back to default
        $stmt = $this->pdo->prepare("
            SELECT rate_percentage FROM commission_rates
            WHERE (sales_rep_id = :rep_id OR sales_rep_id IS NULL)
              AND commission_type = :type
              AND effective_from <= :date
              AND (effective_until IS NULL OR effective_until >= :date)
            ORDER BY sales_rep_id DESC, effective_from DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':rep_id' => $salesRepId,
            ':type' => $type,
            ':date' => $date
        ]);
        $rate = $stmt->fetchColumn();

        return $rate !== false ? (float)$rate : 4.00; // Default 4%
    }

    /**
     * Calculate commission for a single order
     *
     * @return array|null Commission data or null if no commission applies
     */
    public function calculateForOrder(int $orderId): ?array
    {
        // Get order with customer and invoice details
        $stmt = $this->pdo->prepare("
            SELECT
                o.id as order_id,
                o.sales_rep_id,
                o.customer_id,
                o.created_at as order_date,
                c.assigned_sales_rep_id,
                i.id as invoice_id,
                i.total_usd,
                i.total_lbp,
                i.created_at as invoice_date
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            JOIN invoices i ON i.order_id = o.id
            WHERE o.id = :order_id
            AND i.status IN ('issued', 'paid')
        ");
        $stmt->execute([':order_id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null; // No valid invoice for this order
        }

        // Determine who gets the commission
        $salesRepId = null;
        $commissionType = null;

        if (!empty($order['sales_rep_id'])) {
            // Direct sale - the rep who made the sale gets the commission
            $salesRepId = (int)$order['sales_rep_id'];
            $commissionType = 'direct_sale';
        } elseif (!empty($order['assigned_sales_rep_id'])) {
            // Customer self-order - assigned rep gets the commission
            $salesRepId = (int)$order['assigned_sales_rep_id'];
            $commissionType = 'assigned_customer';
        }

        if ($salesRepId === null) {
            return null; // No rep to assign commission to
        }

        // Get the rate
        $invoiceDate = $order['invoice_date'] ?? $order['order_date'];
        $rate = $this->getRate($salesRepId, $commissionType, $invoiceDate);

        $totalUsd = (float)$order['total_usd'];
        $totalLbp = (float)$order['total_lbp'];

        return [
            'order_id' => (int)$order['order_id'],
            'invoice_id' => (int)$order['invoice_id'],
            'sales_rep_id' => $salesRepId,
            'commission_type' => $commissionType,
            'order_total_usd' => $totalUsd,
            'order_total_lbp' => $totalLbp,
            'rate_percentage' => $rate,
            'commission_amount_usd' => round($totalUsd * ($rate / 100), 2),
            'commission_amount_lbp' => round($totalLbp * ($rate / 100), 0),
        ];
    }

    /**
     * Calculate commissions for all orders in a period
     *
     * @param string $startDate Period start (Y-m-d)
     * @param string $endDate Period end (Y-m-d)
     * @param int|null $salesRepId Optional: calculate only for specific rep
     * @return array Results with 'calculated', 'skipped', 'errors' counts
     */
    public function calculateForPeriod(string $startDate, string $endDate, ?int $salesRepId = null): array
    {
        $results = [
            'calculated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total_commission_usd' => 0,
        ];

        // Get all eligible orders in period that don't already have commission calculated
        $sql = "
            SELECT DISTINCT o.id as order_id
            FROM orders o
            JOIN invoices i ON i.order_id = o.id
            WHERE i.status IN ('issued', 'paid')
            AND i.created_at >= :start_date
            AND i.created_at <= :end_date
            AND NOT EXISTS (
                SELECT 1 FROM commission_calculations cc
                WHERE cc.order_id = o.id
            )
        ";

        $params = [
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59',
        ];

        // If specific rep, only get orders where they would be the commission recipient
        if ($salesRepId !== null) {
            $sql .= " AND (
                o.sales_rep_id = :rep_id
                OR (o.sales_rep_id IS NULL AND EXISTS (
                    SELECT 1 FROM customers c
                    WHERE c.id = o.customer_id AND c.assigned_sales_rep_id = :rep_id2
                ))
            )";
            $params[':rep_id'] = $salesRepId;
            $params[':rep_id2'] = $salesRepId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($orderIds as $orderId) {
            $commission = $this->calculateForOrder((int)$orderId);

            if ($commission === null) {
                $results['skipped']++;
                continue;
            }

            // Insert commission calculation
            try {
                $insertStmt = $this->pdo->prepare("
                    INSERT INTO commission_calculations
                    (sales_rep_id, order_id, invoice_id, commission_type, order_total_usd, order_total_lbp,
                     rate_percentage, commission_amount_usd, commission_amount_lbp, status, period_start, period_end)
                    VALUES
                    (:sales_rep_id, :order_id, :invoice_id, :type, :total_usd, :total_lbp,
                     :rate, :comm_usd, :comm_lbp, 'calculated', :period_start, :period_end)
                ");
                $insertStmt->execute([
                    ':sales_rep_id' => $commission['sales_rep_id'],
                    ':order_id' => $commission['order_id'],
                    ':invoice_id' => $commission['invoice_id'],
                    ':type' => $commission['commission_type'],
                    ':total_usd' => $commission['order_total_usd'],
                    ':total_lbp' => $commission['order_total_lbp'],
                    ':rate' => $commission['rate_percentage'],
                    ':comm_usd' => $commission['commission_amount_usd'],
                    ':comm_lbp' => $commission['commission_amount_lbp'],
                    ':period_start' => $startDate,
                    ':period_end' => $endDate,
                ]);

                $results['calculated']++;
                $results['total_commission_usd'] += $commission['commission_amount_usd'];
            } catch (PDOException $e) {
                // Likely duplicate - skip
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Get commission summary by sales rep for a period
     */
    public function getSummaryByRep(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                u.id as sales_rep_id,
                u.name as sales_rep_name,
                COUNT(DISTINCT cc.order_id) as order_count,
                SUM(cc.order_total_usd) as total_sales_usd,
                SUM(cc.commission_amount_usd) as total_commission_usd,
                SUM(cc.commission_amount_lbp) as total_commission_lbp,
                SUM(CASE WHEN cc.status = 'calculated' THEN cc.commission_amount_usd ELSE 0 END) as pending_usd,
                SUM(CASE WHEN cc.status = 'approved' THEN cc.commission_amount_usd ELSE 0 END) as approved_usd,
                SUM(CASE WHEN cc.status = 'paid' THEN cc.commission_amount_usd ELSE 0 END) as paid_usd
            FROM users u
            LEFT JOIN commission_calculations cc ON cc.sales_rep_id = u.id
                AND cc.period_start >= :start_date AND cc.period_end <= :end_date
            WHERE u.role = 'sales_rep'
            GROUP BY u.id, u.name
            ORDER BY total_commission_usd DESC
        ");
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approve commissions by IDs
     */
    public function approveCommissions(array $commissionIds, int $approvedBy): int
    {
        if (empty($commissionIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($commissionIds), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE commission_calculations
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id IN ($placeholders) AND status = 'calculated'
        ");

        $params = array_merge([$approvedBy], $commissionIds);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Mark commissions as paid (called when recording a commission payment)
     */
    public function markAsPaid(array $commissionIds): int
    {
        if (empty($commissionIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($commissionIds), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE commission_calculations
            SET status = 'paid'
            WHERE id IN ($placeholders) AND status = 'approved'
        ");
        $stmt->execute($commissionIds);

        return $stmt->rowCount();
    }

    /**
     * Get detailed commissions for a sales rep
     */
    public function getCommissionsForRep(int $salesRepId, string $startDate, string $endDate, ?string $status = null): array
    {
        $sql = "
            SELECT
                cc.id,
                cc.order_id,
                cc.invoice_id,
                cc.commission_type,
                cc.order_total_usd,
                cc.rate_percentage,
                cc.commission_amount_usd,
                cc.commission_amount_lbp,
                cc.status,
                cc.created_at,
                i.invoice_number,
                c.name as customer_name
            FROM commission_calculations cc
            JOIN invoices i ON i.id = cc.invoice_id
            JOIN orders o ON o.id = cc.order_id
            JOIN customers c ON c.id = o.customer_id
            WHERE cc.sales_rep_id = :sales_rep_id
            AND cc.period_start >= :start_date
            AND cc.period_end <= :end_date
        ";

        $params = [
            ':sales_rep_id' => $salesRepId,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        if ($status !== null) {
            $sql .= " AND cc.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY cc.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
