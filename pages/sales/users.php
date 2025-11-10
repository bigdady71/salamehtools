<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$navLinks = sales_portal_nav_links();
$title = 'Assigned Users';

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => $title,
    'subtitle' => 'Placeholder for managing customer users tied to this sales rep.',
    'user' => $user,
    'nav_links' => $navLinks,
    'active' => 'users',
    'extra_head' => sales_portal_placeholder_styles(),
]);
?>

<section class="placeholder-card">
    <h2>Customer Contacts Workspace</h2>
    <p>
        This page will eventually show the customers, store owners, and buyers assigned to the currently signed-in
        representative, along with quick actions to add new accounts.
    </p>

    <div class="placeholder-grid">
        <div class="placeholder-tile">
            <span>Directory</span>
            <strong>Assigned Accounts</strong>
            <p>Filterable list for all customers mapped to this rep with visit cadence details.</p>
        </div>
        <div class="placeholder-tile">
            <span>Creation</span>
            <strong>Quick Add Form</strong>
            <p>Guided wizard to capture new user basics, route, and preferred payment terms.</p>
        </div>
        <div class="placeholder-tile">
            <span>Engagement</span>
            <strong>Activity Notes</strong>
            <p>Space for latest call notes, visit outcomes, and follow-up reminders.</p>
        </div>
    </div>

    <ul class="placeholder-list">
        <li>
            <span>Assigned users table</span>
            <span class="placeholder-badge">Scaffolding Ready</span>
        </li>
        <li>
            <span>Add user modal</span>
            <span class="placeholder-badge">Scaffolding Ready</span>
        </li>
    </ul>
</section>

<?php sales_portal_render_layout_end(); ?>
