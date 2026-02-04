<?php

declare(strict_types=1);

/**
 * Helper utilities for rendering accounting portal pages with a shared layout.
 */

if (!function_exists('accounting_nav_links')) {
    function accounting_nav_links(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
            'inventory' => ['label' => 'Inventory', 'href' => 'inventory.php'],
            'sales' => ['label' => 'Sales', 'href' => 'sales.php'],
            'commissions' => ['label' => 'Commissions', 'href' => 'commissions.php'],
            'commission_rates' => ['label' => 'Commission Rates', 'href' => 'commission_rates.php'],
            'commission_payments' => ['label' => 'Commission Payments', 'href' => 'commission_payments.php'],
            'customer_balances' => ['label' => 'Customer Balances', 'href' => 'customer_balances.php'],
            'receivables' => ['label' => 'Receivables', 'href' => 'receivables.php'],
            'invoices' => ['label' => 'Invoices', 'href' => 'invoices.php'],
            'reports' => ['label' => 'Reports', 'href' => 'reports.php'],
        ];
    }
}

if (!function_exists('accounting_render_layout_start')) {
    function accounting_render_layout_start(array $options = []): void
    {
        $title = (string)($options['title'] ?? 'Accounting');
        $heading = (string)($options['heading'] ?? $title);
        $subtitle = $options['subtitle'] ?? null;
        $active = (string)($options['active'] ?? '');
        $user = $options['user'] ?? null;
        $extraHead = (string)($options['extra_head'] ?? '');

        $navItems = accounting_nav_links();
        $escTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $escHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $escSubtitle = $subtitle !== null ? htmlspecialchars((string)$subtitle, ENT_QUOTES, 'UTF-8') : null;
        $displayName = null;
        $displayRole = null;

        if (is_array($user)) {
            $name = trim((string)($user['name'] ?? ''));
            $displayName = $name !== '' ? $name : trim((string)($user['email'] ?? ''));
            $displayRole = (string)($user['role'] ?? '');
            if ($displayRole !== '') {
                $displayRole = ucwords(str_replace('_', ' ', $displayRole));
            } else {
                $displayRole = null;
            }
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        require_once __DIR__ . '/assets.php';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>', $escTitle, ' - Accounting</title>';
        echo $extraHead;
        echo '<link rel="stylesheet" href="', asset_url('/css/app.css'), '">';
        echo '<style>';
        echo ':root{--bg:#f3f4f6;--bg-panel:#ffffff;--bg-panel-alt:#f9fafc;--text:#111827;--muted:#6b7280;';
        echo '--accent:#059669;--accent-2:#10b981;--border:#e5e7eb;--ok:#065f46;--warn:#92400e;--err:#991b1b;}';
        echo '*{box-sizing:border-box;}body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,sans-serif;';
        echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;}a{color:var(--accent);text-decoration:none;}';
        echo 'a:hover{text-decoration:underline;}';
        echo '.layout{display:flex;flex:1;}.sidebar{width:260px;background:#ffffff;border-right:1px solid var(--border);padding:28px 20px;display:flex;';
        echo 'flex-direction:column;gap:32px;position:fixed;top:0;left:0;bottom:0;overflow-y:auto;}';
        echo '.brand{font-size:1.4rem;font-weight:700;letter-spacing:.04em;color:var(--accent);}';
        echo '.brand small{display:block;font-size:0.75rem;font-weight:400;color:var(--muted);letter-spacing:0;}';
        echo '.nav-links{display:flex;flex-direction:column;gap:4px;}';
        echo '.nav-links a{padding:10px 12px;border-radius:8px;font-size:0.9rem;color:var(--muted);transition:background .2s,color .2s;}';
        echo '.nav-links a:hover{background:#f0fdf4;color:var(--text);text-decoration:none;}';
        echo '.nav-links a.active{background:var(--accent);color:#fff;font-weight:600;}';
        echo '.user-card{margin-top:auto;padding:14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-panel-alt);font-size:0.85rem;color:var(--muted);}';
        echo '.user-card strong{display:block;font-size:0.95rem;color:var(--text);}';
        echo '.main{flex:1;padding:32px;display:flex;flex-direction:column;gap:24px;margin-left:260px;}';
        echo '.page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;}';
        echo '.page-header h1{margin:0;font-size:1.75rem;}';
        echo '.page-header p{margin:4px 0 0;color:var(--muted);font-size:0.9rem;}';
        echo '.card{background:var(--bg-panel);border-radius:12px;padding:24px;border:1px solid var(--border);box-shadow:0 4px 12px rgba(0,0,0,0.04);}';
        echo '.card h2{margin:0 0 12px;font-size:1.2rem;}';
        echo '.card p{margin:0;color:var(--muted);line-height:1.6;}';
        echo '.actions{display:flex;gap:10px;flex-wrap:wrap;}';
        echo '.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;';
        echo 'background:#fff;border:1px solid var(--border);color:var(--text);font-weight:500;font-size:0.9rem;cursor:pointer;text-decoration:none;}';
        echo '.btn:hover{background:#f9fafb;text-decoration:none;}';
        echo '.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}';
        echo '.btn-primary:hover{background:#047857;}';
        echo '.btn-danger{background:#dc2626;border-color:#dc2626;color:#fff;}';
        echo '.btn-danger:hover{background:#b91c1c;}';
        echo '.btn-sm{padding:6px 10px;font-size:0.8rem;}';
        echo '.metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}';
        echo '.metric-card{background:linear-gradient(135deg,#ffffff 0%,#f9fafb 100%);border-radius:12px;padding:20px;border:1px solid var(--border);}';
        echo '.metric-card .label{font-size:0.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;}';
        echo '.metric-card .value{font-size:1.75rem;font-weight:700;color:var(--text);margin:4px 0;}';
        echo '.metric-card .sub{font-size:0.85rem;color:var(--muted);}';
        echo '.badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;}';
        echo '.badge-success{background:#d1fae5;color:#065f46;}';
        echo '.badge-warning{background:#fef3c7;color:#92400e;}';
        echo '.badge-danger{background:#fee2e2;color:#991b1b;}';
        echo '.badge-info{background:#dbeafe;color:#1e40af;}';
        echo '.badge-neutral{background:#f3f4f6;color:#4b5563;}';
        echo 'table{width:100%;border-collapse:collapse;font-size:0.9rem;}';
        echo 'th,td{padding:12px;text-align:left;border-bottom:1px solid var(--border);}';
        echo 'th{background:#f9fafb;font-weight:600;color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;}';
        echo 'tr:hover{background:#f9fafb;}';
        echo '.filters{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}';
        echo '.filter-input{padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:0.9rem;}';
        echo '.filter-input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(5,150,105,0.1);}';
        echo '.tabs{display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:20px;}';
        echo '.tab{padding:10px 16px;color:var(--muted);font-weight:500;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;}';
        echo '.tab:hover{color:var(--text);}';
        echo '.tab.active{color:var(--accent);border-bottom-color:var(--accent);}';
        echo '.flash{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.9rem;}';
        echo '.flash-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}';
        echo '.flash-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}';
        echo '.flash-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}';
        echo '.flash-info{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}';
        echo '.flash-close{float:right;background:none;border:none;font-size:1.2rem;cursor:pointer;color:inherit;opacity:0.7;}';
        echo '.flash-close:hover{opacity:1;}';
        echo '.text-right{text-align:right;}';
        echo '.text-center{text-align:center;}';
        echo '.text-muted{color:var(--muted);}';
        echo '.text-success{color:var(--ok);}';
        echo '.text-danger{color:var(--err);}';
        echo '.text-warning{color:var(--warn);}';
        echo '.mb-0{margin-bottom:0;}';
        echo '.mb-1{margin-bottom:8px;}';
        echo '.mb-2{margin-bottom:16px;}';
        echo '.mt-2{margin-top:16px;}';
        echo '</style>';
        echo '<link rel="stylesheet" href="', asset_url('/css/accounting.css'), '">';
        echo '</head><body class="theme-light"><div class="layout"><aside class="sidebar">';
        echo '<div class="brand">Salameh Tools<small>Accounting Portal</small></div><nav class="nav-links">';

        foreach ($navItems as $slug => $item) {
            $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
            $classes = 'nav-link' . ($slug === $active ? ' active' : '');
            echo '<a class="', $classes, '" href="', $href, '">', $label, '</a>';
        }

        echo '</nav>';
        echo '<div class="user-card">';

        if ($displayName !== null && $displayName !== '') {
            echo '<strong>', htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'), '</strong>';
        } else {
            echo '<strong>Accountant</strong>';
        }

        if ($displayRole) {
            echo '<span>', htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8'), '</span>';
        } else {
            echo '<span>Signed in</span>';
        }

        echo '<a href="../logout.php" style="display:inline-block;margin-top:10px;padding:6px 10px;background:rgba(239,68,68,0.1);color:#dc2626;border-radius:6px;font-weight:500;font-size:0.8rem;border:1px solid rgba(239,68,68,0.2);text-decoration:none;">Logout</a>';

        echo '</div></aside><main class="main">';
        echo '<header class="page-header"><div><h1>', $escHeading, '</h1>';

        if ($escSubtitle !== null) {
            echo '<p>', $escSubtitle, '</p>';
        }

        echo '</div>';

        if (!empty($options['actions']) && is_array($options['actions'])) {
            echo '<div class="actions">';
            foreach ($options['actions'] as $action) {
                $label = htmlspecialchars((string)($action['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $href = htmlspecialchars((string)($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
                $class = 'btn' . (!empty($action['variant']) ? ' btn-' . htmlspecialchars((string)$action['variant'], ENT_QUOTES, 'UTF-8') : '');
                echo '<a class="', $class, '" href="', $href, '">', $label, '</a>';
            }
            echo '</div>';
        }

        echo '</header>';
    }
}

if (!function_exists('accounting_render_layout_end')) {
    function accounting_render_layout_end(): void
    {
        echo '</main></div></body></html>';
    }
}

if (!function_exists('accounting_render_flashes')) {
    function accounting_render_flashes(array $flashes): void
    {
        if (!$flashes) {
            return;
        }

        foreach ($flashes as $flash) {
            $type = htmlspecialchars($flash['type'] ?? 'info', ENT_QUOTES, 'UTF-8');
            $message = trim((string)($flash['message'] ?? ''));
            $dismissible = !empty($flash['dismissible']);

            echo '<div class="flash flash-', $type, '" role="alert">';
            if ($dismissible) {
                echo '<button type="button" class="flash-close" onclick="this.parentElement.remove()">&times;</button>';
            }
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            echo '</div>';
        }
    }
}

/**
 * Check if current user has accounting access
 */
if (!function_exists('require_accounting_access')) {
    function require_accounting_access(): array
    {
        require_once __DIR__ . '/guard.php';
        require_once __DIR__ . '/db.php';

        require_login();
        $user = auth_user();

        if (!$user || !in_array($user['role'] ?? '', ['admin', 'accountant'], true)) {
            http_response_code(403);
            echo '<!doctype html><html><head><title>Access Denied</title></head><body>';
            echo '<h1>403 - Access Denied</h1>';
            echo '<p>You do not have permission to access the Accounting module.</p>';
            echo '<p><a href="../logout.php">Logout</a></p>';
            echo '</body></html>';
            exit;
        }

        return $user;
    }
}

/**
 * Format currency for display
 */
if (!function_exists('format_currency_usd')) {
    function format_currency_usd(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}

if (!function_exists('format_currency_lbp')) {
    function format_currency_lbp(float $amount): string
    {
        return 'LBP ' . number_format($amount, 0);
    }
}
