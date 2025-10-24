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

$title = 'Admin · Warehouse';

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Warehouse',
    'subtitle' => 'Inventory health and replenishment planning.',
    'active' => 'warehouse',
    'user' => $user,
]);
?>

<section class="card">
    <h2>Operational overview</h2>
    <p>This page will become the go-to dashboard for stock levels, movements and replenishment tasks.</p>
    <ul>
        <li>Snapshot of on-hand quantity versus safety stock and reorder points.</li>
        <li>Inbound and outbound movement logs with responsible team members.</li>
        <li>Open transfer requests or purchase orders awaiting receipt.</li>
        <li>Alerts for items requiring immediate attention.</li>
    </ul>
</section>

<section class="card">
    <h2>Implementation checklist</h2>
    <p>Pull baseline figures from the <code>products</code> table plus any warehouse movement tables. Reuse the low-stock logic from <code>dashboard.php</code> to keep signals consistent.</p>
</section>

<?php
admin_render_layout_end();
