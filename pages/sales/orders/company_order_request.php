<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/admin_page.php';
require_once __DIR__ . '/../../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Company Order Request';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Placeholder for submitting warehouse-prepared orders on behalf of customers.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'orders_request',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>Warehouse Fulfilment Flow</h2>
    <p>
        Sales reps will draft company orders here when stock needs to ship from the warehouse instead of the van.
        The layout below outlines the upcoming steps and data panes.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>Brief</span>
            <strong>Order Requirements</strong>
            <p>Capture requested delivery date, payment terms, and fulfillment notes.</p>
        </div>
        <div class="placeholder-tile">
            <span>Line Items</span>
            <strong>Warehouse Picker</strong>
            <p>Product selector with live warehouse availability and substitution suggestions.</p>
        </div>
        <div class="placeholder-tile">
            <span>Handover</span>
            <strong>Submission &amp; Tracking</strong>
            <p>Trigger workflow for approvals, pick-ticket generation, and status tracking.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Request form container</span>
            <span class="placeholder-badge">Awaiting Data Hook</span>
        </li>
        <li>
            <span>Status timeline</span>
            <span class="placeholder-badge">Awaiting Data Hook</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
