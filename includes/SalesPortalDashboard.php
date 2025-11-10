<?php

declare(strict_types=1);

/**
 * Build all data required for the sales representative dashboard while ensuring row-level scoping.
 *
 * @return array{
 *     metrics: array<string,int>,
 *     deliveries_today:int,
 *     invoice_totals: array<string,float>,
 *     latest_orders: array<int,array<string,mixed>>,
 *     upcoming_deliveries: array<int,array<string,mixed>>,
 *     pending_invoices: array<int,array<string,mixed>>,
 *     recent_payments: array<int,array<string,mixed>>,
 *     van_stock_summary: array<string,float|int>,
 *     van_stock_movements: array<int,array<string,mixed>>,
 *     errors: array<int,string>,
 *     notices: array<int,string>
 * }
 */
function sales_portal_dashboard_data(PDO $pdo, int $salesRepId): array
{
    $errors = [];
    $notices = [];

    $latestStatusSql = "
        SELECT ose.order_id, ose.status, ose.created_at
        FROM order_status_events ose
        INNER JOIN (
            SELECT order_id, MAX(id) AS max_id
            FROM order_status_events
            WHERE status <> 'invoice_created'
            GROUP BY order_id
        ) latest ON latest.order_id = ose.order_id AND latest.max_id = ose.id
        WHERE ose.status <> 'invoice_created'
    ";

    $scalar = static function (string $label, string $sql, array $params) use ($pdo, &$errors) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? 0 : (int)$value;
        } catch (PDOException $e) {
            $errors[] = "{$label}: {$e->getMessage()}";
            return 0;
        }
    };

    $fetchAll = static function (string $label, string $sql, array $params) use ($pdo, &$errors) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $errors[] = "{$label}: {$e->getMessage()}";
            return [];
        }
    };

    $metrics = [
        'orders_today' => $scalar(
            'Orders today',
            'SELECT COUNT(*) FROM orders WHERE sales_rep_id = :rep_id AND DATE(created_at) = CURRENT_DATE()',
            [':rep_id' => $salesRepId]
        ),
        'open_orders' => $scalar(
            'Open orders',
            "
                SELECT COUNT(*)
                FROM orders o
                LEFT JOIN ({$latestStatusSql}) current_status ON current_status.order_id = o.id
                WHERE o.sales_rep_id = :rep_id
                  AND (
                    current_status.status IS NULL
                    OR current_status.status NOT IN ('delivered','cancelled','returned')
                  )
            ",
            [':rep_id' => $salesRepId]
        ),
        'awaiting_approval' => $scalar(
            'Awaiting approval',
            "
                SELECT COUNT(*)
                FROM orders o
                LEFT JOIN ({$latestStatusSql}) current_status ON current_status.order_id = o.id
                WHERE o.sales_rep_id = :rep_id
                  AND COALESCE(current_status.status, 'on_hold') = 'on_hold'
            ",
            [':rep_id' => $salesRepId]
        ),
        'in_transit' => $scalar(
            'In transit',
            "
                SELECT COUNT(*)
                FROM orders o
                JOIN ({$latestStatusSql}) current_status ON current_status.order_id = o.id
                WHERE o.sales_rep_id = :rep_id
                  AND current_status.status = 'in_transit'
            ",
            [':rep_id' => $salesRepId]
        ),
    ];

    $deliveriesToday = $scalar(
        'Deliveries today',
        "
            SELECT COUNT(*)
            FROM deliveries d
            INNER JOIN orders o ON o.id = d.order_id
            WHERE o.sales_rep_id = :rep_id
              AND DATE(d.scheduled_at) = CURRENT_DATE()
        ",
        [':rep_id' => $salesRepId]
    );

    $invoiceTotals = [
        'usd' => 0.0,
        'lbp' => 0.0,
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(GREATEST(i.total_usd - IFNULL(paid.paid_usd, 0), 0)), 0) AS usd,
                COALESCE(SUM(GREATEST(i.total_lbp - IFNULL(paid.paid_lbp, 0), 0)), 0) AS lbp
            FROM invoices i
            INNER JOIN orders o ON o.id = i.order_id
            LEFT JOIN (
                SELECT invoice_id,
                       SUM(amount_usd) AS paid_usd,
                       SUM(amount_lbp) AS paid_lbp
                FROM payments
                GROUP BY invoice_id
            ) paid ON paid.invoice_id = i.id
            WHERE o.sales_rep_id = :rep_id
              AND i.status <> 'voided'
        ");
        $stmt->execute([':rep_id' => $salesRepId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invoiceTotals['usd'] = (float)$row['usd'];
            $invoiceTotals['lbp'] = (float)$row['lbp'];
        }
    } catch (PDOException $e) {
        $errors[] = "Invoice exposure: {$e->getMessage()}";
    }

    $latestOrders = $fetchAll(
        'Latest orders',
        "
            SELECT o.id,
                   o.order_number,
                   o.created_at,
                   o.total_usd,
                   o.total_lbp,
                   c.name AS customer_name,
                   COALESCE(current_status.status, 'on_hold') AS status,
                   current_status.created_at AS status_changed_at
            FROM orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN ({$latestStatusSql}) current_status ON current_status.order_id = o.id
            WHERE o.sales_rep_id = :rep_id
            ORDER BY o.created_at DESC
            LIMIT 6
        ",
        [':rep_id' => $salesRepId]
    );

    $pendingInvoices = $fetchAll(
        'Pending invoices',
        "
            SELECT i.id,
                   i.invoice_number,
                   i.status,
                   i.total_usd,
                   i.total_lbp,
                   i.created_at,
                   c.name AS customer_name,
                   COALESCE(paid.paid_usd, 0) AS paid_usd,
                   COALESCE(paid.paid_lbp, 0) AS paid_lbp
            FROM invoices i
            INNER JOIN orders o ON o.id = i.order_id
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN (
                SELECT invoice_id,
                       SUM(amount_usd) AS paid_usd,
                       SUM(amount_lbp) AS paid_lbp
                FROM payments
                GROUP BY invoice_id
            ) paid ON paid.invoice_id = i.id
            WHERE o.sales_rep_id = :rep_id
              AND i.status <> 'voided'
            ORDER BY i.created_at DESC
            LIMIT 6
        ",
        [':rep_id' => $salesRepId]
    );

    $recentPayments = $fetchAll(
        'Recent payments',
        "
            SELECT p.id,
                   p.invoice_id,
                   p.amount_usd,
                   p.amount_lbp,
                   p.method,
                   p.received_at,
                   i.invoice_number,
                   c.name AS customer_name
            FROM payments p
            INNER JOIN invoices i ON i.id = p.invoice_id
            INNER JOIN orders o ON o.id = i.order_id
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.sales_rep_id = :rep_id
            ORDER BY p.received_at DESC, p.id DESC
            LIMIT 6
        ",
        [':rep_id' => $salesRepId]
    );

    $upcomingDeliveries = $fetchAll(
        'Upcoming deliveries',
        "
            SELECT d.id,
                   d.order_id,
                   d.scheduled_at,
                   d.status,
                   c.name AS customer_name
            FROM deliveries d
            INNER JOIN orders o ON o.id = d.order_id
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.sales_rep_id = :rep_id
              AND d.scheduled_at >= NOW()
            ORDER BY d.scheduled_at ASC
            LIMIT 6
        ",
        [':rep_id' => $salesRepId]
    );

    $vanStockSummary = [
        'sku_count' => 0,
        'total_units' => 0.0,
        'total_value_usd' => 0.0,
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS sku_count,
                COALESCE(SUM(s.qty_on_hand), 0) AS total_units,
                COALESCE(SUM(s.qty_on_hand * p.sale_price_usd), 0) AS total_value_usd
            FROM s_stock s
            INNER JOIN products p ON p.id = s.product_id
            WHERE s.salesperson_id = :rep_id
        ");
        $stmt->execute([':rep_id' => $salesRepId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vanStockSummary['sku_count'] = (int)$row['sku_count'];
            $vanStockSummary['total_units'] = (float)$row['total_units'];
            $vanStockSummary['total_value_usd'] = (float)$row['total_value_usd'];
        }
    } catch (PDOException $e) {
        $errors[] = "Van stock summary: {$e->getMessage()}";
    }

    $vanStockMovements = $fetchAll(
        'Recent van stock movements',
        "
            SELECT m.id,
                   m.delta_qty,
                   m.reason,
                   m.created_at,
                   p.item_name,
                   p.sku
            FROM s_stock_movements m
            INNER JOIN products p ON p.id = m.product_id
            WHERE m.salesperson_id = :rep_id
            ORDER BY m.created_at DESC
            LIMIT 5
        ",
        [':rep_id' => $salesRepId]
    );

    return [
        'metrics' => $metrics,
        'deliveries_today' => $deliveriesToday,
        'invoice_totals' => $invoiceTotals,
        'latest_orders' => $latestOrders,
        'upcoming_deliveries' => $upcomingDeliveries,
        'pending_invoices' => $pendingInvoices,
        'recent_payments' => $recentPayments,
        'van_stock_summary' => $vanStockSummary,
        'van_stock_movements' => $vanStockMovements,
        'errors' => $errors,
        'notices' => $notices,
    ];
}
