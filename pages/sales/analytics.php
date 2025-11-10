<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Analytics & Statistics';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Placeholder dashboards for performance KPIs, heatmaps, and visit productivity.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'analytics',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>Performance Command Center</h2>
    <p>
        This layout will soon host the sales representative scorecards: revenue pacing, strike rate, customer
        coverage, and opportunity heatmaps.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>KPIs</span>
            <strong>Quota Tracking</strong>
            <p>Widgets for monthly targets, achievement, and forecasted attainment.</p>
        </div>
        <div class="placeholder-tile">
            <span>Coverage</span>
            <strong>Route Insights</strong>
            <p>Space for visit frequency charts and territory penetration analytics.</p>
        </div>
        <div class="placeholder-tile">
            <span>Opportunities</span>
            <strong>Product Mix</strong>
            <p>Mini dashboards to highlight upsell/cross-sell momentum.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Visualization slots</span>
            <span class="placeholder-badge">Awaiting Data</span>
        </li>
        <li>
            <span>Export/report actions</span>
            <span class="placeholder-badge">Awaiting Data</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
