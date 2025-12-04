<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$title = 'Inventory Adjustments - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Inventory Adjustments',
    'subtitle' => 'Correct stock discrepancies and record adjustments',
    'user' => $user,
    'active' => 'adjustments',
]);
?>

<div class="card" style="background:#fef3c7;border-color:#f59e0b;margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="font-size:3rem;">🔧</div>
        <div>
            <h2 style="margin:0 0 8px;color:#92400e;">Inventory Adjustments</h2>
            <p style="margin:0;color:#92400e;">
                This feature will be implemented to record stock corrections, damages, and found items.
            </p>
        </div>
    </div>
</div>

<div class="card">
    <h2>Coming Soon</h2>
    <p>The adjustments module will allow you to:</p>
    <ul style="line-height:2;">
        <li>✓ Record stock corrections</li>
        <li>✓ Document damaged items</li>
        <li>✓ Track found inventory</li>
        <li>✓ Add adjustment notes and reasons</li>
        <li>✓ Maintain audit trail</li>
    </ul>
</div>

<?php
warehouse_portal_render_layout_end();
