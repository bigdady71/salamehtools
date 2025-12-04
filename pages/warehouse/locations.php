<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$title = 'Warehouse Locations - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Warehouse Locations',
    'subtitle' => 'Manage bins, aisles, and storage locations',
    'user' => $user,
    'active' => 'locations',
]);
?>

<div class="card" style="background:#e0e7ff;border-color:#6366f1;margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="font-size:3rem;">📍</div>
        <div>
            <h2 style="margin:0 0 8px;color:#3730a3;">Location Management</h2>
            <p style="margin:0;color:#3730a3;">
                This feature will be implemented to organize warehouse layout and optimize picking routes.
            </p>
        </div>
    </div>
</div>

<div class="card">
    <h2>Coming Soon</h2>
    <p>The locations module will allow you to:</p>
    <ul style="line-height:2;">
        <li>✓ Define warehouse zones and aisles</li>
        <li>✓ Create shelf and bin locations</li>
        <li>✓ Assign products to specific locations</li>
        <li>✓ Generate optimized pick paths</li>
        <li>✓ Track location capacity</li>
    </ul>
</div>

<?php
warehouse_portal_render_layout_end();
