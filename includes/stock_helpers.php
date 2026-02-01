<?php

declare(strict_types=1);

/**
 * Stock Helper Functions
 * 
 * Provides backward-compatible helper functions that use the new StockManager.
 * These functions can be used across admin, sales, and customer portals.
 * 
 * FIXED EXCHANGE RATE: 1 USD = 90,000 LBP
 */

require_once __DIR__ . '/StockManager.php';

/**
 * Get the fixed exchange rate (1 USD = 90,000 LBP)
 * This replaces the dynamic exchange rate lookup.
 * 
 * @return int
 */
function get_exchange_rate(): int
{
    return StockManager::EXCHANGE_RATE_LBP;
}

/**
 * Convert USD to LBP using fixed rate
 * 
 * @param float $usd
 * @return float
 */
function usd_to_lbp(float $usd): float
{
    return StockManager::usdToLbp($usd);
}

/**
 * Convert LBP to USD using fixed rate
 * 
 * @param float $lbp
 * @return float
 */
function lbp_to_usd(float $lbp): float
{
    return StockManager::lbpToUsd($lbp);
}

/**
 * Get StockManager instance (singleton pattern)
 * 
 * @param PDO|null $pdo
 * @return StockManager
 */
function get_stock_manager(?PDO $pdo = null): StockManager
{
    static $instance = null;

    if ($instance === null || $pdo !== null) {
        $instance = new StockManager($pdo ?? db());
    }

    return $instance;
}

/**
 * Check warehouse stock availability
 * 
 * @param PDO $pdo
 * @param int $productId
 * @param float $quantity
 * @return bool
 */
function check_warehouse_stock(PDO $pdo, int $productId, float $quantity): bool
{
    $manager = new StockManager($pdo);
    return $manager->hasWarehouseStock($productId, $quantity);
}

/**
 * Check van stock availability
 * 
 * @param PDO $pdo
 * @param int $salesRepId
 * @param int $productId
 * @param float $quantity
 * @return bool
 */
function check_van_stock(PDO $pdo, int $salesRepId, int $productId, float $quantity): bool
{
    $manager = new StockManager($pdo);
    return $manager->hasVanStock($salesRepId, $productId, $quantity);
}

/**
 * Get warehouse stock for a product
 * 
 * @param PDO $pdo
 * @param int $productId
 * @return float
 */
function get_warehouse_stock(PDO $pdo, int $productId): float
{
    $manager = new StockManager($pdo);
    return $manager->getWarehouseStock($productId);
}

/**
 * Get van stock for a sales rep and product
 * 
 * @param PDO $pdo
 * @param int $salesRepId
 * @param int $productId
 * @return float
 */
function get_van_stock(PDO $pdo, int $salesRepId, int $productId): float
{
    $manager = new StockManager($pdo);
    return $manager->getVanStock($salesRepId, $productId);
}

/**
 * Get all van stock for a sales rep
 * 
 * @param PDO $pdo
 * @param int $salesRepId
 * @param bool $includeZero
 * @return array
 */
function get_all_van_stock(PDO $pdo, int $salesRepId, bool $includeZero = false): array
{
    $manager = new StockManager($pdo);
    return $manager->getAllVanStock($salesRepId, $includeZero);
}

/**
 * Get products with availability (respects filter settings)
 * 
 * @param PDO $pdo
 * @param array $filters
 * @return array
 */
function get_available_products(PDO $pdo, array $filters = []): array
{
    $manager = new StockManager($pdo);
    return $manager->getProductAvailability($filters);
}

/**
 * Get product filter settings
 * 
 * @param PDO $pdo
 * @return array
 */
function get_product_filter_settings(PDO $pdo): array
{
    $manager = new StockManager($pdo);
    return $manager->getFilterSettings();
}

/**
 * Update product filter settings
 * 
 * @param PDO $pdo
 * @param array $settings
 * @return bool
 */
function update_product_filter_settings(PDO $pdo, array $settings): bool
{
    $manager = new StockManager($pdo);
    return $manager->updateFilterSettings($settings);
}

/**
 * Format price for display (wholesale only by default)
 * 
 * @param float $wholesaleUsd
 * @param float|null $retailUsd Optional retail price
 * @param bool $showBoth Show both prices if retail is provided
 * @return string
 */
function format_price_display(float $wholesaleUsd, ?float $retailUsd = null, bool $showBoth = false): string
{
    $wholesaleLbp = usd_to_lbp($wholesaleUsd);

    $output = sprintf('$%.2f / %s L.L.', $wholesaleUsd, number_format($wholesaleLbp, 0));

    if ($showBoth && $retailUsd !== null && $retailUsd > 0) {
        $retailLbp = usd_to_lbp($retailUsd);
        $output .= sprintf(' (Retail: $%.2f / %s L.L.)', $retailUsd, number_format($retailLbp, 0));
    }

    return $output;
}

/**
 * Format price for display - USD only
 * 
 * @param float $usd
 * @return string
 */
function format_usd(float $usd): string
{
    return sprintf('$%s', number_format($usd, 2));
}

/**
 * Format price for display - LBP only
 * 
 * @param float $lbp
 * @return string
 */
function format_lbp(float $lbp): string
{
    return sprintf('%s L.L.', number_format($lbp, 0));
}

/**
 * Soft delete a product
 * 
 * @param PDO $pdo
 * @param int $productId
 * @return bool
 */
function soft_delete_product(PDO $pdo, int $productId): bool
{
    $manager = new StockManager($pdo);
    return $manager->softDeleteProduct($productId);
}

/**
 * Restore a soft-deleted product
 * 
 * @param PDO $pdo
 * @param int $productId
 * @return bool
 */
function restore_product(PDO $pdo, int $productId): bool
{
    $manager = new StockManager($pdo);
    return $manager->restoreProduct($productId);
}

/**
 * Build product query with soft delete filter
 * Returns the WHERE clause addition for soft delete
 * 
 * @param string $tableAlias
 * @param bool $includeDeleted
 * @return string
 */
function soft_delete_condition(string $tableAlias = 'p', bool $includeDeleted = false): string
{
    if ($includeDeleted) {
        return '';
    }
    return "{$tableAlias}.deleted_at IS NULL";
}