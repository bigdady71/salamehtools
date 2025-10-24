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

$title = 'Admin · Invoices';

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Invoices',
    'subtitle' => 'Billing oversight and reconciliation space.',
    'active' => 'invoices',
    'user' => $user,
]);
?>

<section class="card">
    <h2>Planned capabilities</h2>
    <p>Centralize invoice creation, dispatch and payment reconciliation so finance always knows the cash position.</p>
    <ul>
        <li>Sortable grid showing invoice totals, currency breakdown and outstanding balances.</li>
        <li>Status chips for draft, sent, partially paid, paid and voided invoices.</li>
        <li>Links to related orders, customers and payment receipts.</li>
        <li>Bulk export to PDF or CSV for accounting handoffs.</li>
    </ul>
</section>

<section class="card">
    <h2>Data pointers</h2>
    <p>Leverage the <code>invoices</code> table along with <code>payments</code> for aggregating balances. The fallback logic already implemented in the dashboard metrics can be repurposed here.</p>
</section>

<?php
admin_render_layout_end();
