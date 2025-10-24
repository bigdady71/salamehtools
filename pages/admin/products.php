<?php

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/admin_page.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$title = 'Admin · Products';

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Products',
    'subtitle' => 'Catalogue management tools coming soon.',
    'active' => 'products',
    'user' => $user,
    'actions' => [
        ['label' => 'Import CSV', 'href' => 'products_import.php', 'variant' => 'primary'],
    ],
]);
?>

<section class="card">
    <h2>Planned features</h2>
    <p>This workspace will centralize everything related to product records so the team can keep the catalogue clean and actionable.</p>
    <ul>
        <li>Searchable inventory list with stock levels, reorder thresholds and status flags.</li>
        <li>Inline edit and bulk actions for pricing, availability and category updates.</li>
        <li>Quick links to import or export product data for spreadsheet workflows.</li>
        <li>Integration hooks for warehouse and sales teams to stay aligned.</li>
    </ul>
</section>

<section class="card">
    <h2>Getting started suggestions</h2>
    <p>When you are ready to build, start with a paginated table sourced from the <code>products</code> table and layer on filters for brand, stock status and active flag. Reuse the styling helpers from <code>users.php</code> for consistent forms.</p>
</section>

<?php
admin_render_layout_end();
