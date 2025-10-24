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

$title = 'Admin · Orders';

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Orders',
    'subtitle' => 'End-to-end order tracking and approvals hub.',
    'active' => 'orders',
    'user' => $user,
]);
?>

<section class="card">
    <h2>Workflow vision</h2>
    <p>Use this page to surface every order’s lifecycle at a glance so sales, accounting and logistics stay synchronized.</p>
    <ul>
        <li>Master order list with filters for status, sales rep and creation date.</li>
        <li>Inline previews of payment state, delivery slots and outstanding tasks.</li>
        <li>Quick actions to approve, put on hold or reassign ownership.</li>
        <li>Deep-link into the delivery schedule and invoice timelines when required.</li>
    </ul>
</section>

<section class="card">
    <h2>Implementation notes</h2>
    <p>Start by reusing the status join logic already written in <code>dashboard.php</code> for the latest status lookup. The <code>orders</code>, <code>order_items</code> and <code>order_status_events</code> tables will drive the primary grid.</p>
</section>

<?php
admin_render_layout_end();
