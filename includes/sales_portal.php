<?php

declare(strict_types=1);

use SalamehTools\Middleware\RBACMiddleware;

/**
 * Ensure the current session belongs to a sales representative and return the user data.
 *
 * @return array<string, mixed>
 */
function sales_portal_bootstrap(): array
{
    require_login();
    RBACMiddleware::requireRole('sales_rep', 'Access denied. Sales representatives only.');

    return auth_user();
}

/**
 * Navigation links available to sales representatives.
 *
 * @return array<string, array{label:string,href:string}>
 */
function sales_portal_nav_links(): array
{
    // Determine if we're in a subdirectory (like /pages/sales/orders/)
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $inSubdir = strpos($scriptPath, '/pages/sales/orders/') !== false;
    $prefix = $inSubdir ? '../' : '';

    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'href' => $prefix . 'dashboard.php',
        ],
        'users' => [
            'label' => 'Customers',
            'href' => $prefix . 'users.php',
        ],
        'products' => [
            'label' => 'Products',
            'href' => $prefix . 'products.php',
        ],
        'van_stock' => [
            'label' => 'Van Stock',
            'href' => $prefix . 'van_stock.php',
        ],
        'warehouse_stock' => [
            'label' => 'Warehouse Stock',
            'href' => $prefix . 'warehouse_stock.php',
        ],
        'orders' => [
            'label' => 'My Orders',
            'href' => $prefix . 'orders.php',
        ],
        'orders_van' => [
            'label' => 'New Van Sale',
            'href' => $prefix . 'orders/van_stock_sales.php',
        ],
        'orders_request' => [
            'label' => 'New Company Order',
            'href' => $prefix . 'orders/company_order_request.php',
        ],
        'invoices' => [
            'label' => 'Invoices',
            'href' => $prefix . 'invoices.php',
        ],
        'receivables' => [
            'label' => 'AR/Collections',
            'href' => $prefix . 'receivables.php',
        ],
        'analytics' => [
            'label' => 'Analytics',
            'href' => $prefix . 'analytics.php',
        ],
    ];
}

/**
 * Renders the start of a sales portal page layout with a dedicated sales sidebar.
 *
 * @param array<string, mixed> $options
 */
function sales_portal_render_layout_start(array $options = []): void
{
    $title = (string)($options['title'] ?? 'Sales Portal');
    $heading = (string)($options['heading'] ?? $title);
    $subtitle = $options['subtitle'] ?? null;
    $active = (string)($options['active'] ?? '');
    $user = $options['user'] ?? null;
    $extraHead = (string)($options['extra_head'] ?? '');

    // Determine if we're in a subdirectory for logout path
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $inSubdir = strpos($scriptPath, '/pages/sales/orders/') !== false;

    $navItems = sales_portal_nav_links();
    if (isset($options['nav_links']) && is_array($options['nav_links']) && $options['nav_links']) {
        $navItems = $options['nav_links'];
    }

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
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>', $escTitle, '</title>';
    echo $extraHead;
    echo '<link rel="stylesheet" href="/css/app.css?v=1">';
    echo '<style>';
    echo ':root{--bg:#f3f4f6;--bg-panel:#ffffff;--bg-panel-alt:#f9fafc;--text:#111827;--muted:#6b7280;';
    echo '--accent:#0ea5e9;--accent-2:#06b6d4;--border:#e5e7eb;--sales-gradient:linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%);}';
    echo '*{box-sizing:border-box;}body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,sans-serif;';
    echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;}a{color:var(--accent);text-decoration:none;}';
    echo 'a:hover{text-decoration:underline;}';
    echo '.layout{display:flex;flex:1;}.sidebar{width:260px;background:var(--sales-gradient);border-right:none;padding:28px 20px;display:flex;';
    echo 'flex-direction:column;gap:32px;box-shadow:4px 0 12px rgba(0,0,0,0.08);}';
    echo '.brand{font-size:1.7rem;font-weight:800;letter-spacing:.04em;color:#ffffff;text-shadow:0 2px 4px rgba(0,0,0,0.1);}';
    echo '.brand small{display:block;font-size:0.75rem;font-weight:400;opacity:0.9;margin-top:4px;letter-spacing:0.1em;}';
    echo '.nav-links{display:flex;flex-direction:column;gap:6px;}';
    echo '.nav-links a{padding:12px 14px;border-radius:10px;font-size:0.92rem;color:rgba(255,255,255,0.85);transition:all .2s;font-weight:500;}';
    echo '.nav-links a:hover{background:rgba(255,255,255,0.15);color:#ffffff;text-decoration:none;transform:translateX(4px);}';
    echo '.nav-links a.active{background:#ffffff;color:var(--accent);font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.15);}';
    echo '.user-card{margin-top:auto;padding:16px;border-radius:12px;border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.1);';
    echo 'font-size:0.9rem;color:rgba(255,255,255,0.85);backdrop-filter:blur(10px);}';
    echo '.user-card strong{display:block;font-size:1rem;color:#ffffff;font-weight:600;}';
    echo '.main{flex:1;padding:36px;display:flex;flex-direction:column;gap:24px;}';
    echo '.page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:18px;}';
    echo '.page-header h1{margin:0;font-size:2rem;}';
    echo '.page-header p{margin:4px 0 0;color:var(--muted);font-size:0.95rem;}';
    echo '.card{background:var(--bg-panel);border-radius:16px;padding:28px;border:1px solid var(--border);box-shadow:0 24px 40px rgba(15,23,42,0.08);}';
    echo '.card h2{margin:0 0 12px;font-size:1.4rem;}';
    echo '.card p{margin:0;color:var(--muted);line-height:1.6;}';
    echo '.card ul{margin:0;padding-left:20px;color:var(--muted);line-height:1.6;}';
    echo '.card ul li{margin-bottom:6px;}';
    echo '.actions{display:flex;gap:12px;flex-wrap:wrap;}';
    echo '.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;';
    echo 'background:#fff;border:1px solid var(--border);color:var(--text);font-weight:600;text-decoration:none;}';
    echo '.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}';
    echo '.btn-primary:hover{box-shadow:0 0 0 4px rgba(14,165,233,0.18);}';
    echo '@media (max-width:900px){.layout{flex-direction:column;}.sidebar{width:auto;flex-direction:row;';
    echo 'align-items:center;justify-content:space-between;padding:18px 22px;border-right:none;border-bottom:1px solid rgba(255,255,255,0.2);}';
    echo '.nav-links{flex-direction:row;flex-wrap:wrap;gap:6px;}.nav-links a{padding:8px 10px;}';
    echo '.user-card{margin-top:0;}';
    echo '.main{padding:24px;}}';
    echo '@media (max-width:640px){.sidebar{flex-direction:column;align-items:flex-start;gap:16px;}';
    echo '.nav-links{width:100%;}.nav-links a{width:100%;}}';
    echo '</style></head><body class="theme-light"><div class="layout"><aside class="sidebar">';
    echo '<div class="brand">Salameh Tools<small>SALES PORTAL</small></div><nav class="nav-links">';

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
        echo '<strong>Sales Representative</strong>';
    }

    if ($displayRole) {
        echo '<span>', htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8'), '</span>';
    } else {
        echo '<span>Signed in</span>';
    }

    // Logout button
    $logoutPath = $inSubdir ? '../../logout.php' : '../logout.php';
    echo '<a href="', htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8'), '" style="display: inline-block; margin-top: 12px; padding: 8px 12px; background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-radius: 8px; text-align: center; font-weight: 600; font-size: 0.85rem; border: 1px solid rgba(239, 68, 68, 0.3); text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background=\'rgba(239, 68, 68, 0.25)\'; this.style.color=\'#ffffff\';" onmouseout="this.style.background=\'rgba(239, 68, 68, 0.15)\'; this.style.color=\'#fca5a5\';">🚪 Logout</a>';

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

/**
 * Renders the end of a sales portal page layout.
 */
function sales_portal_render_layout_end(): void
{
    echo '</main></div></body></html>';
}

/**
 * Shared lightweight styling for the placeholder containers that make up the scaffolding.
 */
function sales_portal_placeholder_styles(): string
{
    return <<<'HTML'
<style>
.placeholder-card {
    background: var(--bg-panel);
    border: 1px dashed var(--border);
    border-radius: 20px;
    padding: 32px;
    box-shadow: 0 18px 28px rgba(15, 23, 42, 0.08);
}
.placeholder-card h2 {
    margin: 0 0 12px;
    font-size: 1.4rem;
}
.placeholder-card p {
    margin: 0;
    color: var(--muted);
    line-height: 1.6;
}
.placeholder-grid {
    margin-top: 24px;
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}
.placeholder-tile {
    border: 1px dashed var(--border);
    border-radius: 16px;
    min-height: 140px;
    padding: 20px;
    background: var(--bg-panel-alt);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.placeholder-tile span {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
}
.placeholder-tile strong {
    font-size: 1.1rem;
}
.placeholder-list {
    list-style: none;
    margin: 20px 0 0;
    padding: 0;
}
.placeholder-list li {
    padding: 12px 0;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    color: var(--muted);
}
.placeholder-list li:first-child {
    border-top: none;
}
.placeholder-badge {
    background: rgba(31, 111, 235, 0.08);
    color: var(--accent);
    border-radius: 9999px;
    padding: 4px 12px;
    font-size: 0.75rem;
    font-weight: 600;
}
</style>
HTML;
}
