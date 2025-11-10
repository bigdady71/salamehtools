<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Invoices';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Placeholder space for receivables linked to this reps customers and orders.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'invoices',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>Collections Overview</h2>
    <p>
        Here we will surface open invoices, payment status, and quick settlement actions so field teams can stay on
        top of collections during visits.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>Receivables</span>
            <strong>Aging Buckets</strong>
            <p>KPIs for current, 30, 60, and 90+ day exposure.</p>
        </div>
        <div class="placeholder-tile">
            <span>Invoices</span>
            <strong>Listing Container</strong>
            <p>Table placeholder for invoice number, customer, balance, and next action.</p>
        </div>
        <div class="placeholder-tile">
            <span>Actions</span>
            <strong>Payment Capture</strong>
            <p>Reserved area for recording cash collections or tagging handovers.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Invoice filters</span>
            <span class="placeholder-badge">Coming Soon</span>
        </li>
        <li>
            <span>Payment timeline</span>
            <span class="placeholder-badge">Coming Soon</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
