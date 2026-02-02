<?php

/**
 * Product Visibility Filter Helper
 * 
 * This file provides functions to apply product visibility filters
 * based on settings configured in the admin panel.
 */

declare(strict_types=1);

/**
 * Get product visibility filter SQL conditions
 * 
 * @param PDO $pdo Database connection
 * @param string $tableAlias Table alias for products table (default: 'p')
 * @return string SQL WHERE conditions to filter products (without leading AND/OR)
 */
function get_product_filter_conditions(PDO $pdo, string $tableAlias = 'p'): string
{
    $conditions = [];

    // Get settings from database
    $hideZeroStock = get_product_filter_setting($pdo, 'hide_zero_stock');
    $hideZeroRetailPrice = get_product_filter_setting($pdo, 'hide_zero_retail_price');
    $hideZeroWholesalePrice = get_product_filter_setting($pdo, 'hide_zero_wholesale_price');
    $hideSamePrices = get_product_filter_setting($pdo, 'hide_same_prices');
    $hideZeroStockAndPrice = get_product_filter_setting($pdo, 'hide_zero_stock_and_price');
    $minQuantityThreshold = (int)get_product_filter_setting($pdo, 'min_quantity_threshold', '0');

    // Build conditions
    if ($hideZeroStock) {
        $conditions[] = "{$tableAlias}.quantity_on_hand > 0";
    }

    if ($hideZeroRetailPrice) {
        $conditions[] = "{$tableAlias}.sale_price_usd > 0";
    }

    if ($hideZeroWholesalePrice) {
        $conditions[] = "{$tableAlias}.wholesale_price_usd > 0";
    }

    if ($hideSamePrices) {
        $conditions[] = "ABS({$tableAlias}.sale_price_usd - {$tableAlias}.wholesale_price_usd) > 0.001";
    }

    if ($hideZeroStockAndPrice) {
        // Hide only if BOTH are zero
        $conditions[] = "NOT ({$tableAlias}.quantity_on_hand <= 0 AND {$tableAlias}.wholesale_price_usd <= 0)";
    }

    if ($minQuantityThreshold > 0) {
        $conditions[] = "{$tableAlias}.quantity_on_hand >= {$minQuantityThreshold}";
    }

    if (empty($conditions)) {
        return '1=1'; // No filters, return always-true condition
    }

    return implode(' AND ', $conditions);
}

/**
 * Get a single product filter setting
 * 
 * @param PDO $pdo Database connection
 * @param string $key Setting key (without 'product_filter.' prefix)
 * @param string $default Default value
 * @return string Setting value
 */
function get_product_filter_setting(PDO $pdo, string $key, string $default = '0'): string
{
    static $cache = null;

    if ($cache === null) {
        // Load all product filter settings at once
        $cache = [];
        try {
            $stmt = $pdo->query("SELECT k, v FROM settings WHERE k LIKE 'product_filter.%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $shortKey = str_replace('product_filter.', '', $row['k']);
                $cache[$shortKey] = $row['v'];
            }
        } catch (PDOException $e) {
            // If table doesn't exist yet, return defaults
            return $default;
        }
    }

    return $cache[$key] ?? $default;
}

/**
 * Check if product filters are enabled (at least one filter is active)
 * 
 * @param PDO $pdo Database connection
 * @return bool True if any filter is enabled
 */
function has_active_product_filters(PDO $pdo): bool
{
    return get_product_filter_setting($pdo, 'hide_zero_stock') === '1'
        || get_product_filter_setting($pdo, 'hide_zero_retail_price') === '1'
        || get_product_filter_setting($pdo, 'hide_zero_wholesale_price') === '1'
        || get_product_filter_setting($pdo, 'hide_same_prices') === '1'
        || get_product_filter_setting($pdo, 'hide_zero_stock_and_price') === '1'
        || (int)get_product_filter_setting($pdo, 'min_quantity_threshold', '0') > 0;
}

/**
 * Get product filter description for UI display
 * 
 * @param PDO $pdo Database connection
 * @return array List of active filter descriptions
 */
function get_active_filter_descriptions(PDO $pdo): array
{
    $descriptions = [];

    if (get_product_filter_setting($pdo, 'hide_zero_stock') === '1') {
        $descriptions[] = 'Hiding products with zero stock';
    }

    if (get_product_filter_setting($pdo, 'hide_zero_retail_price') === '1') {
        $descriptions[] = 'Hiding products with zero retail price';
    }

    if (get_product_filter_setting($pdo, 'hide_zero_wholesale_price') === '1') {
        $descriptions[] = 'Hiding products with zero wholesale price';
    }

    if (get_product_filter_setting($pdo, 'hide_same_prices') === '1') {
        $descriptions[] = 'Hiding products where retail = wholesale price';
    }

    if (get_product_filter_setting($pdo, 'hide_zero_stock_and_price') === '1') {
        $descriptions[] = 'Hiding products with both zero stock and zero price';
    }

    $minQty = (int)get_product_filter_setting($pdo, 'min_quantity_threshold', '0');
    if ($minQty > 0) {
        $descriptions[] = "Hiding products with stock below {$minQty}";
    }

    return $descriptions;
}
