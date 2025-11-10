<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Van Stock';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Future home for live van inventory, replenishment alerts, and movement audit trails.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'van_stock',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>Van Inventory Overview</h2>
    <p>
        This space will visualize each rep’s rolling stock levels, shipment receipts, and transfers back to the
        warehouse. Metrics and tables shown below are purely structural placeholders.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>Capacity</span>
            <strong>Stock Totals</strong>
            <p>Widgets for SKU counts, unit value, and critical low-stock warnings.</p>
        </div>
        <div class="placeholder-tile">
            <span>Movements</span>
            <strong>Recent Activity</strong>
            <p>Timeline stream for loadings, van sales deductions, and manual adjustments.</p>
        </div>
        <div class="placeholder-tile">
            <span>Tasks</span>
            <strong>Replenishment Queue</strong>
            <p>Upcoming resupply actions with quick links to request additional stock.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Inventory table</span>
            <span class="placeholder-badge">Coming Soon</span>
        </li>
        <li>
            <span>Movement ledger</span>
            <span class="placeholder-badge">Coming Soon</span>
        </li>
        <li>
            <span>Replenishment actions</span>
            <span class="placeholder-badge">Coming Soon</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
