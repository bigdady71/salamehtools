<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/admin_page.php';
require_once __DIR__ . '/../../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Van Stock Sales Order';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Placeholder for selling directly from on-board inventory.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'orders_van',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>On-the-Spot Sales Flow</h2>
    <p>
        Field reps will initiate instant invoices from van stock here. The final experience will combine product
        picking, customer selection, and payment capture in one streamlined flow.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>Step 1</span>
            <strong>Customer Selection</strong>
            <p>Lookup drawer for assigned accounts, recent orders, and owed balances.</p>
        </div>
        <div class="placeholder-tile">
            <span>Step 2</span>
            <strong>Van Stock Picker</strong>
            <p>Interface to subtract quantities from current van inventory with availability hints.</p>
        </div>
        <div class="placeholder-tile">
            <span>Step 3</span>
            <strong>Summary &amp; Confirmation</strong>
            <p>Review totals, add discounts, and capture signature/payment method.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Order builder container</span>
            <span class="placeholder-badge">Scaffolding Ready</span>
        </li>
        <li>
            <span>Payment capture modal</span>
            <span class="placeholder-badge">Scaffolding Ready</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
