<?php

/**
 * Product Visibility Filter Helper
 * 
 * This file provides functions to apply product visibility filters
 * based on settings configured in the admin panel.
 */

declare(strict_types=1);

/**
 * Check if filters should be applied to a specific user role
 * 
 * @param PDO $pdo Database connection
 * @param string $role User role to check (customer, sales_rep, warehouse, accountant, admin)
 * @return bool True if filters should be applied to this role
 */
function should_apply_product_filters(PDO $pdo, string $role): bool
{
    // Admins always see all products
    if ($role === 'admin' || $role === 'super_admin') {
        return false;
    }

    // Get the roles that filters apply to
    $rolesJson = get_product_filter_setting($pdo, 'apply_to_roles', '');
    if (empty($rolesJson)) {
        // Default: apply to customer and sales_rep
        return in_array($role, ['customer', 'sales_rep']);
    }

    $roles = json_decode($rolesJson, true);
    if (!is_array($roles)) {
        return in_array($role, ['customer', 'sales_rep']);
    }

    return in_array($role, $roles);
}

/**
 * Get the list of roles that filters apply to
 * 
 * @param PDO $pdo Database connection
 * @return array List of role names
 */
function get_filter_applied_roles(PDO $pdo): array
{
    $rolesJson = get_product_filter_setting($pdo, 'apply_to_roles', '');
    if (empty($rolesJson)) {
        return ['customer', 'sales_rep']; // Default
    }

    $roles = json_decode($rolesJson, true);
    return is_array($roles) ? $roles : ['customer', 'sales_rep'];
}

/**
 * Get product visibility filter SQL conditions
 * 
 * @param PDO $pdo Database connection
 * @param string $tableAlias Table alias for products table (default: 'p')
 * @param string|null $userRole Optional user role - if provided, checks if filters apply to this role
 * @return string SQL WHERE conditions to filter products (without leading AND/OR)
 */
function get_product_filter_conditions(PDO $pdo, string $tableAlias = 'p', ?string $userRole = null): string
{
    // If user role is provided, check if filters should apply
    if ($userRole !== null && !should_apply_product_filters($pdo, $userRole)) {
        return '1=1'; // No filters for this role
    }

    $conditions = [];

    // Get settings from database
    $hideZeroStock = get_product_filter_setting($pdo, 'hide_zero_stock') === '1';
    $hideZeroRetailPrice = get_product_filter_setting($pdo, 'hide_zero_retail_price') === '1';
    $hideZeroWholesalePrice = get_product_filter_setting($pdo, 'hide_zero_wholesale_price') === '1';
    $hideSamePrices = get_product_filter_setting($pdo, 'hide_same_prices') === '1';
    $hideZeroStockAndPrice = get_product_filter_setting($pdo, 'hide_zero_stock_and_price') === '1';
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
 * @param string|null $userRole Optional user role to check
 * @return bool True if any filter is enabled and applies to the role
 */
function has_active_product_filters(PDO $pdo, ?string $userRole = null): bool
{
    // If role provided, check if filters apply
    if ($userRole !== null && !should_apply_product_filters($pdo, $userRole)) {
        return false;
    }

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

    // Add which roles are affected
    $roles = get_filter_applied_roles($pdo);
    if (!empty($roles)) {
        $roleNames = [
            'customer' => 'Customers',
            'sales_rep' => 'Sales Reps',
            'warehouse' => 'Warehouse',
            'accountant' => 'Accountants'
        ];
        $roleLabels = array_map(fn($r) => $roleNames[$r] ?? $r, $roles);
        $descriptions[] = 'Applied to: ' . implode(', ', $roleLabels);
    }

    return $descriptions;
}
