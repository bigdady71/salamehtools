<?php

declare(strict_types=1);

/**
 * Price Display Helpers
 * 
 * STRICT PRICE DISPLAY RULE:
 * - Default: Show ONLY wholesale price
 * - If more than one price is shown: it MUST be wholesale + retail (no other combinations)
 * 
 * FIXED EXCHANGE RATE: 1 USD = 90,000 LBP
 */

// Fixed exchange rate as per business rules
const EXCHANGE_RATE_LBP = 90000;

/**
 * Get the fixed exchange rate (1 USD = 90,000 LBP)
 * 
 * @return int
 */
function get_fixed_exchange_rate(): int
{
    return EXCHANGE_RATE_LBP;
}

/**
 * Convert USD to LBP using fixed rate
 * 
 * @param float $usd
 * @return float
 */
function convert_usd_to_lbp(float $usd): float
{
    return $usd * EXCHANGE_RATE_LBP;
}

/**
 * Convert LBP to USD using fixed rate
 * 
 * @param float $lbp
 * @return float
 */
function convert_lbp_to_usd(float $lbp): float
{
    return $lbp / EXCHANGE_RATE_LBP;
}

/**
 * Format price for display - WHOLESALE ONLY (default)
 * 
 * @param float $wholesaleUsd The wholesale price in USD
 * @param bool $showLbp Whether to also show LBP equivalent
 * @return string Formatted price string
 */
function format_wholesale_price(float $wholesaleUsd, bool $showLbp = true): string
{
    $formatted = '$' . number_format($wholesaleUsd, 2);

    if ($showLbp) {
        $lbp = convert_usd_to_lbp($wholesaleUsd);
        $formatted .= ' / ' . number_format($lbp, 0) . ' L.L.';
    }

    return $formatted;
}

/**
 * Format price for display - WHOLESALE + RETAIL (only valid dual-price option)
 * 
 * @param float $wholesaleUsd The wholesale price in USD
 * @param float $retailUsd The retail price in USD
 * @param bool $showLbp Whether to also show LBP equivalents
 * @return array Array with 'wholesale' and 'retail' formatted strings
 */
function format_dual_price(float $wholesaleUsd, float $retailUsd, bool $showLbp = true): array
{
    return [
        'wholesale' => format_wholesale_price($wholesaleUsd, $showLbp),
        'retail' => format_wholesale_price($retailUsd, $showLbp), // Same format, different label
    ];
}

/**
 * Format USD amount only
 * 
 * @param float $usd
 * @return string
 */
function format_usd_amount(float $usd): string
{
    return '$' . number_format($usd, 2);
}

/**
 * Format LBP amount only
 * 
 * @param float $lbp
 * @return string
 */
function format_lbp_amount(float $lbp): string
{
    return number_format($lbp, 0) . ' L.L.';
}

/**
 * Get the display price for a product (WHOLESALE ONLY by default)
 * 
 * @param array $product Product array with wholesale_price_usd
 * @param bool $includeRetail Whether to include retail price (if available)
 * @return array Price display data
 */
function get_product_price_display(array $product, bool $includeRetail = false): array
{
    $wholesaleUsd = (float)($product['wholesale_price_usd'] ?? 0);
    $retailUsd = (float)($product['sale_price_usd'] ?? $product['retail_price_usd'] ?? 0);

    $result = [
        'wholesale_usd' => $wholesaleUsd,
        'wholesale_lbp' => convert_usd_to_lbp($wholesaleUsd),
        'wholesale_formatted' => format_wholesale_price($wholesaleUsd),
        'display_price' => format_wholesale_price($wholesaleUsd), // Default display
    ];

    if ($includeRetail && $retailUsd > 0) {
        $result['retail_usd'] = $retailUsd;
        $result['retail_lbp'] = convert_usd_to_lbp($retailUsd);
        $result['retail_formatted'] = format_wholesale_price($retailUsd);
        $result['has_retail'] = true;
    } else {
        $result['has_retail'] = false;
    }

    return $result;
}

/**
 * Calculate order total in both currencies
 * 
 * @param float $totalUsd Total in USD
 * @return array Array with 'usd', 'lbp', 'usd_formatted', 'lbp_formatted'
 */
function calculate_order_total(float $totalUsd): array
{
    $totalLbp = convert_usd_to_lbp($totalUsd);

    return [
        'usd' => $totalUsd,
        'lbp' => $totalLbp,
        'usd_formatted' => format_usd_amount($totalUsd),
        'lbp_formatted' => format_lbp_amount($totalLbp),
    ];
}

/**
 * Calculate payment breakdown
 * 
 * @param float $paidUsd Amount paid in USD
 * @param float $paidLbp Amount paid in LBP
 * @param float $totalUsd Total amount due in USD
 * @return array Payment breakdown
 */
function calculate_payment_breakdown(float $paidUsd, float $paidLbp, float $totalUsd): array
{
    $paidLbpAsUsd = convert_lbp_to_usd($paidLbp);
    $totalPaidUsd = $paidUsd + $paidLbpAsUsd;
    $remainingUsd = max(0, $totalUsd - $totalPaidUsd);
    $overpaymentUsd = max(0, $totalPaidUsd - $totalUsd);

    return [
        'paid_usd' => $paidUsd,
        'paid_lbp' => $paidLbp,
        'paid_lbp_as_usd' => $paidLbpAsUsd,
        'total_paid_usd' => $totalPaidUsd,
        'remaining_usd' => $remainingUsd,
        'remaining_lbp' => convert_usd_to_lbp($remainingUsd),
        'overpayment_usd' => $overpaymentUsd,
        'overpayment_lbp' => convert_usd_to_lbp($overpaymentUsd),
        'is_fully_paid' => $remainingUsd < 0.01,
        'has_overpayment' => $overpaymentUsd > 0.01,
    ];
}

/**
 * HTML helper: Render price display (wholesale only)
 * 
 * @param float $wholesaleUsd
 * @param string $cssClass Optional CSS class
 * @return string HTML
 */
function render_price_html(float $wholesaleUsd, string $cssClass = 'price'): string
{
    $lbp = convert_usd_to_lbp($wholesaleUsd);

    return sprintf(
        '<span class="%s"><strong>$%s</strong> <span class="price-lbp">/ %s L.L.</span></span>',
        htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8'),
        number_format($wholesaleUsd, 2),
        number_format($lbp, 0)
    );
}

/**
 * HTML helper: Render dual price display (wholesale + retail)
 * 
 * @param float $wholesaleUsd
 * @param float $retailUsd
 * @return string HTML
 */
function render_dual_price_html(float $wholesaleUsd, float $retailUsd): string
{
    $wholesaleLbp = convert_usd_to_lbp($wholesaleUsd);
    $retailLbp = convert_usd_to_lbp($retailUsd);

    return sprintf(
        '<div class="price-dual">
            <div class="price-wholesale">
                <span class="price-label">Wholesale:</span>
                <strong>$%s</strong> / %s L.L.
            </div>
            <div class="price-retail">
                <span class="price-label">Retail:</span>
                <strong>$%s</strong> / %s L.L.
            </div>
        </div>',
        number_format($wholesaleUsd, 2),
        number_format($wholesaleLbp, 0),
        number_format($retailUsd, 2),
        number_format($retailLbp, 0)
    );
}
