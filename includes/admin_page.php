<?php

declare(strict_types=1);

/**
 * Helper utilities for rendering admin pages with a shared layout.
 */
function admin_nav_links(): array
{
    return [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        'users' => ['label' => 'Users', 'href' => 'users.php'],
        'products' => ['label' => 'Products', 'href' => 'products.php'],
        'orders' => ['label' => 'Orders', 'href' => 'orders.php'],
        'invoices' => ['label' => 'Invoices', 'href' => 'invoices.php'],
        'receivables' => ['label' => 'Receivables', 'href' => 'receivables.php'],
        'warehouse' => ['label' => 'Warehouse', 'href' => 'warehouse_stock.php'],
        'settings' => ['label' => 'Settings', 'href' => 'settings.php'],
    ];
}

function admin_render_layout_start(array $options = []): void
{
    $title = (string)($options['title'] ?? 'Admin');
    $heading = (string)($options['heading'] ?? $title);
    $subtitle = $options['subtitle'] ?? null;
    $active = (string)($options['active'] ?? '');
    $user = $options['user'] ?? null;
    $extraHead = (string)($options['extra_head'] ?? '');

    $navItems = admin_nav_links();
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
    echo '--accent:#1f6feb;--accent-2:#0ea5e9;--border:#e5e7eb;}';
    echo '*{box-sizing:border-box;}body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,sans-serif;';
    echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;}a{color:var(--accent);text-decoration:none;}';
    echo 'a:hover{text-decoration:underline;}';
    echo '.layout{display:flex;flex:1;}.sidebar{width:248px;background:#ffffff;border-right:1px solid var(--border);padding:28px 20px;display:flex;';
    echo 'flex-direction:column;gap:32px;}';
    echo '.brand{font-size:1.6rem;font-weight:700;letter-spacing:.04em;color:var(--accent);}';
    echo '.nav-links{display:flex;flex-direction:column;gap:8px;}';
    echo '.nav-links a{padding:10px 12px;border-radius:10px;font-size:0.95rem;color:var(--muted);transition:background .2s,color .2s,box-shadow .2s;}';
    echo '.nav-links a:hover{background:var(--chip);color:var(--text);box-shadow:0 0 0 3px rgba(31,111,235,0.12);}';
    echo '.nav-links a.active{background:var(--accent);color:#fff;font-weight:600;box-shadow:0 0 0 3px rgba(31,111,235,0.2);}';
    echo '.user-card{margin-top:auto;padding:16px;border-radius:12px;border:1px solid var(--border);background:var(--bg-panel-alt);font-size:0.9rem;color:var(--muted);}';
    echo '.user-card strong{display:block;font-size:1rem;color:var(--text);}';
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
    echo '.btn-primary:hover{box-shadow:0 0 0 4px rgba(31,111,235,0.18);}';
    echo '@media (max-width:900px){.layout{flex-direction:column;}.sidebar{width:auto;flex-direction:row;';
    echo 'align-items:center;justify-content:space-between;padding:18px 22px;border-right:none;border-bottom:1px solid var(--border);}';
    echo '.nav-links{flex-direction:row;flex-wrap:wrap;gap:6px;}.nav-links a{padding:8px 10px;}';
    echo '.user-card{margin-top:0;}';
    echo '.main{padding:24px;}}';
    echo '@media (max-width:640px){.sidebar{flex-direction:column;align-items:flex-start;gap:16px;}';
    echo '.nav-links{width:100%;}.nav-links a{width:100%;}}';
    echo '</style></head><body class="theme-light"><div class="layout"><aside class="sidebar">';
    echo '<div class="brand">Salameh Tools</div><nav class="nav-links">';

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
        echo '<strong>Administrator</strong>';
    }

    if ($displayRole) {
        echo '<span>', htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8'), '</span>';
    } else {
        echo '<span>Signed in</span>';
    }

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

function admin_render_layout_end(): void
{
    echo '</main></div></body></html>';
}
