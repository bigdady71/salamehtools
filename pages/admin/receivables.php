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

$title = 'Admin · Receivables';

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Receivables',
    'subtitle' => 'Collections cockpit to monitor overdue balances.',
    'active' => 'receivables',
    'user' => $user,
]);
?>

<section class="card">
    <h2>Collection pipeline</h2>
    <p>Design this view to highlight which customers need follow-up, why, and who owns the next action.</p>
    <ul>
        <li>Aging buckets (0-30, 31-60, 61-90, 90+) with totals per currency.</li>
        <li>Customer-level drill downs showing open invoices and last contact dates.</li>
        <li>Assignment indicators so finance and sales know who is chasing payment.</li>
        <li>Notes or reminders for promised payment dates.</li>
    </ul>
</section>

<section class="card">
    <h2>Next build steps</h2>
    <p>Combine invoice data with any payment promises table (or add one) to keep receivables organized. Consider exposing exports for weekly finance reports.</p>
</section>

<?php
admin_render_layout_end();
