<?php

declare(strict_types=1);

/**
 * StockManager - Centralized stock management with transaction safety
 * 
 * This class provides a single source of truth for all stock operations:
 * - Warehouse stock (products.quantity_on_hand)
 * - Van/Sales rep stock (s_stock.qty_on_hand)
 * 
 * All operations use transactions and row-level locking to prevent race conditions.
 * 
 * FIXED EXCHANGE RATE: 1 USD = 90,000 LBP
 */
class StockManager
{
    private PDO $pdo;
    private ?int $userId = null;

    // Fixed exchange rate as per business rules
    public const EXCHANGE_RATE_LBP = 90000;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Set the current user for audit logging
     */
    public function setUser(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Get warehouse stock for a product (with optional locking)
     * 
     * @param int $productId
     * @param bool $forUpdate Lock row for update
     * @return float
     */
    public function getWarehouseStock(int $productId, bool $forUpdate = false): float
    {
        $sql = "SELECT quantity_on_hand FROM products WHERE id = :id AND deleted_at IS NULL";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (float)$row['quantity_on_hand'] : 0.0;
    }

    /**
     * Get van stock for a sales rep and product (with optional locking)
     * 
     * @param int $salesRepId
     * @param int $productId
     * @param bool $forUpdate Lock row for update
     * @return float
     */
    public function getVanStock(int $salesRepId, int $productId, bool $forUpdate = false): float
    {
        $sql = "SELECT qty_on_hand FROM s_stock 
                WHERE salesperson_id = :rep_id AND product_id = :product_id AND deleted_at IS NULL";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':rep_id' => $salesRepId, ':product_id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (float)$row['qty_on_hand'] : 0.0;
    }

    /**
     * Get all van stock for a sales rep
     * 
     * @param int $salesRepId
     * @param bool $includeZero Include items with zero stock
     * @return array
     */
    public function getAllVanStock(int $salesRepId, bool $includeZero = false): array
    {
        $stockCondition = $includeZero ? '' : 'AND ss.qty_on_hand > 0';

        $stmt = $this->pdo->prepare("
            SELECT 
                ss.product_id,
                ss.qty_on_hand,
                p.sku,
                p.item_name,
                p.wholesale_price_usd,
                p.image_url,
                p.topcat,
                p.midcat
            FROM s_stock ss
            INNER JOIN products p ON p.id = ss.product_id
            WHERE ss.salesperson_id = :rep_id 
              AND ss.deleted_at IS NULL 
              AND p.deleted_at IS NULL
              AND p.is_active = 1
              {$stockCondition}
            ORDER BY p.item_name
        ");
        $stmt->execute([':rep_id' => $salesRepId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if sufficient warehouse stock is available
     * 
     * @param int $productId
     * @param float $quantity
     * @return bool
     */
    public function hasWarehouseStock(int $productId, float $quantity): bool
    {
        return $this->getWarehouseStock($productId) >= $quantity;
    }

    /**
     * Check if sufficient van stock is available
     * 
     * @param int $salesRepId
     * @param int $productId
     * @param float $quantity
     * @return bool
     */
    public function hasVanStock(int $salesRepId, int $productId, float $quantity): bool
    {
        return $this->getVanStock($salesRepId, $productId) >= $quantity;
    }

    /**
     * Deduct from warehouse stock (within a transaction)
     * 
     * @param int $productId
     * @param float $quantity
     * @param string $reason
     * @param string|null $note
     * @return bool
     * @throws Exception if insufficient stock or not in transaction
     */
    public function deductWarehouseStock(int $productId, float $quantity, string $reason, ?string $note = null): bool
    {
        if (!$this->pdo->inTransaction()) {
            throw new Exception('deductWarehouseStock must be called within a transaction');
        }

        // Lock and verify stock
        $currentStock = $this->getWarehouseStock($productId, true);
        if ($currentStock < $quantity) {
            throw new Exception("Insufficient warehouse stock. Available: {$currentStock}, Requested: {$quantity}");
        }

        // Deduct stock
        $stmt = $this->pdo->prepare("
            UPDATE products 
            SET quantity_on_hand = quantity_on_hand - :qty, updated_at = NOW()
            WHERE id = :product_id AND quantity_on_hand >= :qty AND deleted_at IS NULL
        ");
        $stmt->execute([':qty' => $quantity, ':product_id' => $productId]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Failed to deduct warehouse stock - concurrent modification detected");
        }

        // Log movement
        $this->logWarehouseMovement($productId, -$quantity, $reason, $note);

        return true;
    }

    /**
     * Add to warehouse stock (within a transaction)
     * 
     * @param int $productId
     * @param float $quantity
     * @param string $reason
     * @param string|null $note
     * @return bool
     * @throws Exception if not in transaction
     */
    public function addWarehouseStock(int $productId, float $quantity, string $reason, ?string $note = null): bool
    {
        if (!$this->pdo->inTransaction()) {
            throw new Exception('addWarehouseStock must be called within a transaction');
        }

        $stmt = $this->pdo->prepare("
            UPDATE products 
            SET quantity_on_hand = quantity_on_hand + :qty, updated_at = NOW()
            WHERE id = :product_id AND deleted_at IS NULL
        ");
        $stmt->execute([':qty' => $quantity, ':product_id' => $productId]);

        // Log movement
        $this->logWarehouseMovement($productId, $quantity, $reason, $note);

        return true;
    }

    /**
     * Deduct from van stock (within a transaction)
     * 
     * @param int $salesRepId
     * @param int $productId
     * @param float $quantity
     * @param string $reason
     * @param string|null $note
     * @param int|null $orderItemId
     * @return bool
     * @throws Exception if insufficient stock or not in transaction
     */
    public function deductVanStock(
        int $salesRepId,
        int $productId,
        float $quantity,
        string $reason,
        ?string $note = null,
        ?int $orderItemId = null
    ): bool {
        if (!$this->pdo->inTransaction()) {
            throw new Exception('deductVanStock must be called within a transaction');
        }

        // Lock and verify stock
        $currentStock = $this->getVanStock($salesRepId, $productId, true);
        if ($currentStock < $quantity) {
            throw new Exception("Insufficient van stock. Available: {$currentStock}, Requested: {$quantity}");
        }

        // Deduct stock
        $stmt = $this->pdo->prepare("
            UPDATE s_stock 
            SET qty_on_hand = qty_on_hand - :qty, updated_at = NOW()
            WHERE salesperson_id = :rep_id AND product_id = :product_id 
              AND qty_on_hand >= :qty AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':qty' => $quantity,
            ':rep_id' => $salesRepId,
            ':product_id' => $productId
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Failed to deduct van stock - concurrent modification detected");
        }

        // Log movement
        $this->logVanStockMovement($salesRepId, $productId, -$quantity, $reason, $note, $orderItemId);

        return true;
    }

    /**
     * Add to van stock (within a transaction)
     * 
     * @param int $salesRepId
     * @param int $productId
     * @param float $quantity
     * @param string $reason
     * @param string|null $note
     * @param int|null $orderItemId
     * @return bool
     * @throws Exception if not in transaction
     */
    public function addVanStock(
        int $salesRepId,
        int $productId,
        float $quantity,
        string $reason,
        ?string $note = null,
        ?int $orderItemId = null
    ): bool {
        if (!$this->pdo->inTransaction()) {
            throw new Exception('addVanStock must be called within a transaction');
        }

        // Check if record exists
        $existingStmt = $this->pdo->prepare("
            SELECT id FROM s_stock 
            WHERE salesperson_id = :rep_id AND product_id = :product_id AND deleted_at IS NULL
            FOR UPDATE
        ");
        $existingStmt->execute([':rep_id' => $salesRepId, ':product_id' => $productId]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE s_stock 
                SET qty_on_hand = qty_on_hand + :qty, updated_at = NOW()
                WHERE salesperson_id = :rep_id AND product_id = :product_id AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':qty' => $quantity,
                ':rep_id' => $salesRepId,
                ':product_id' => $productId
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand, created_at, updated_at)
                VALUES (:rep_id, :product_id, :qty, NOW(), NOW())
            ");
            $stmt->execute([
                ':rep_id' => $salesRepId,
                ':product_id' => $productId,
                ':qty' => $quantity
            ]);
        }

        // Log movement
        $this->logVanStockMovement($salesRepId, $productId, $quantity, $reason, $note, $orderItemId);

        return true;
    }

    /**
     * Transfer stock from warehouse to van (atomic operation)
     * 
     * @param int $salesRepId
     * @param int $productId
     * @param float $quantity
     * @param string|null $note
     * @return bool
     */
    public function transferWarehouseToVan(int $salesRepId, int $productId, float $quantity, ?string $note = null): bool
    {
        $this->pdo->beginTransaction();

        try {
            $this->deductWarehouseStock($productId, $quantity, 'transfer_to_van', $note);
            $this->addVanStock($salesRepId, $productId, $quantity, 'transfer_from_warehouse', $note);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Transfer warehouse to van failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Transfer stock from van back to warehouse (atomic operation)
     * 
     * @param int $salesRepId
     * @param int $productId
     * @param float $quantity
     * @param string|null $note
     * @return bool
     */
    public function transferVanToWarehouse(int $salesRepId, int $productId, float $quantity, ?string $note = null): bool
    {
        $this->pdo->beginTransaction();

        try {
            $this->deductVanStock($salesRepId, $productId, $quantity, 'return_to_warehouse', $note);
            $this->addWarehouseStock($productId, $quantity, 'return_from_van', $note);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Transfer van to warehouse failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Log warehouse stock movement
     */
    private function logWarehouseMovement(int $productId, float $deltaQty, string $reason, ?string $note): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO warehouse_movements (product_id, delta_qty, movement_type, reason, note, created_by, created_at)
            VALUES (:product_id, :delta_qty, :movement_type, :reason, :note, :created_by, NOW())
        ");
        $stmt->execute([
            ':product_id' => $productId,
            ':delta_qty' => $deltaQty,
            ':movement_type' => $deltaQty > 0 ? 'in' : 'out',
            ':reason' => $reason,
            ':note' => $note,
            ':created_by' => $this->userId,
        ]);
    }

    /**
     * Log van stock movement
     */
    private function logVanStockMovement(
        int $salesRepId,
        int $productId,
        float $deltaQty,
        string $reason,
        ?string $note,
        ?int $orderItemId = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, order_item_id, note, created_at)
            VALUES (:rep_id, :product_id, :delta_qty, :reason, :order_item_id, :note, NOW())
        ");
        $stmt->execute([
            ':rep_id' => $salesRepId,
            ':product_id' => $productId,
            ':delta_qty' => $deltaQty,
            ':reason' => $reason,
            ':order_item_id' => $orderItemId,
            ':note' => $note,
        ]);
    }

    /**
     * Get product availability for display (respects filter settings)
     * 
     * @param array $filters Optional filters
     * @return array
     */
    public function getProductAvailability(array $filters = []): array
    {
        // Get filter settings from database
        $settings = $this->getFilterSettings();

        $conditions = ['p.deleted_at IS NULL', 'p.is_active = 1'];
        $params = [];

        // Apply hide zero stock filter
        if (!empty($settings['hide_zero_stock']) || !empty($filters['hide_zero_stock'])) {
            $conditions[] = 'p.quantity_on_hand > 0';
        }

        // Apply hide zero price filter
        if (!empty($settings['hide_zero_price']) || !empty($filters['hide_zero_price'])) {
            $conditions[] = 'p.wholesale_price_usd > 0';
        }

        // Apply minimum quantity threshold
        $minQty = (float)($settings['min_quantity_threshold'] ?? 0);
        if ($minQty > 0) {
            $conditions[] = 'p.quantity_on_hand >= :min_qty';
            $params[':min_qty'] = $minQty;
        }

        // Apply hide zero stock AND price filter
        if (!empty($settings['hide_zero_stock_and_price']) || !empty($filters['hide_zero_stock_and_price'])) {
            $conditions[] = 'NOT (p.quantity_on_hand = 0 AND p.wholesale_price_usd = 0)';
        }

        // Search filter
        if (!empty($filters['search'])) {
            $conditions[] = '(p.item_name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // Category filter
        if (!empty($filters['category'])) {
            $conditions[] = 'p.topcat = :category';
            $params[':category'] = $filters['category'];
        }

        $whereClause = implode(' AND ', $conditions);

        $sql = "
            SELECT 
                p.id,
                p.sku,
                p.item_name,
                p.wholesale_price_usd,
                p.sale_price_usd,
                p.quantity_on_hand as warehouse_stock,
                p.topcat,
                p.midcat,
                p.image_url,
                p.unit
            FROM products p
            WHERE {$whereClause}
            ORDER BY p.item_name
        ";

        // Add limit if specified
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get filter settings from database
     */
    public function getFilterSettings(): array
    {
        $stmt = $this->pdo->prepare("SELECT k, v FROM settings WHERE k LIKE 'product_filter.%'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $key = str_replace('product_filter.', '', $row['k']);
            $settings[$key] = $row['v'];
        }

        return $settings;
    }

    /**
     * Update filter settings
     */
    public function updateFilterSettings(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            $stmt = $this->pdo->prepare("
                INSERT INTO settings (k, v) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE v = :v2
            ");
            $stmt->execute([
                ':k' => 'product_filter.' . $key,
                ':v' => $value,
                ':v2' => $value,
            ]);
        }

        return true;
    }

    /**
     * Soft delete a product
     */
    public function softDeleteProduct(int $productId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE products SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $productId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Restore a soft-deleted product
     */
    public function restoreProduct(int $productId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE products SET deleted_at = NULL WHERE id = :id AND deleted_at IS NOT NULL
        ");
        $stmt->execute([':id' => $productId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get the fixed exchange rate (1 USD = 90,000 LBP)
     */
    public static function getExchangeRate(): int
    {
        return self::EXCHANGE_RATE_LBP;
    }

    /**
     * Convert USD to LBP
     */
    public static function usdToLbp(float $usd): float
    {
        return $usd * self::EXCHANGE_RATE_LBP;
    }

    /**
     * Convert LBP to USD
     */
    public static function lbpToUsd(float $lbp): float
    {
        return $lbp / self::EXCHANGE_RATE_LBP;
    }
}
