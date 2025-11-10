<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Product Catalog';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Placeholder for searchable product lists and pricing references.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'products',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>Catalog Snapshot</h2>
    <p>
        Sales reps will use this screen to review active SKUs, tiered pricing, promotions, and availability by
        warehouse. The scaffolding below reserves those sections.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>Search</span>
            <strong>Filters &amp; Segments</strong>
            <p>Future controls for categories, stock flags, and promotional tags.</p>
        </div>
        <div class="placeholder-tile">
            <span>Data</span>
            <strong>Product Table</strong>
            <p>Columns for SKU, unit sizes, USD/LBP pricing, and van-stock eligibility.</p>
        </div>
        <div class="placeholder-tile">
            <span>Reference</span>
            <strong>Key Notes</strong>
            <p>Area for feature bullets, marketing attachments, or spec sheets.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Product grid container</span>
            <span class="placeholder-badge">Awaiting Data Hook</span>
        </li>
        <li>
            <span>Quick add to order action</span>
            <span class="placeholder-badge">Awaiting Data Hook</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
