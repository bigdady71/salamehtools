<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$title = 'Receiving - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Receive Stock',
    'subtitle' => 'Record incoming inventory shipments',
    'user' => $user,
    'active' => 'receiving',
]);
?>

<div class="card" style="background:#dbeafe;border-color:#3b82f6;margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="font-size:3rem;">📥</div>
        <div>
            <h2 style="margin:0 0 8px;color:#1e40af;">Receiving Station</h2>
            <p style="margin:0;color:#1e40af;">
                This feature will be implemented to record incoming stock from suppliers and update inventory levels automatically.
            </p>
        </div>
    </div>
</div>

<div class="card">
    <h2>Coming Soon</h2>
    <p>The receiving module will allow you to:</p>
    <ul style="line-height:2;">
        <li>✓ Scan products as they arrive</li>
        <li>✓ Update stock levels automatically</li>
        <li>✓ Record supplier information</li>
        <li>✓ Print receiving labels</li>
        <li>✓ Track receiving history</li>
    </ul>
    <p style="margin-top:20px;">
        For now, please use the <a href="adjustments.php" style="color:var(--primary);font-weight:600;">Inventory Adjustments</a> page to manually update stock levels.
    </p>
</div>

<?php
warehouse_portal_render_layout_end();
