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
 * Get enabled sidebar links from settings.
 *
 * @return array|null Array of enabled link keys, or null if all enabled
 */
function sales_portal_get_enabled_links(): ?array
{
    static $enabledLinks = null;
    static $loaded = false;

    if ($loaded) {
        return $enabledLinks;
    }

    $loaded = true;

    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = :key");
        $stmt->execute([':key' => 'sales_portal.sidebar_links']);
        $result = $stmt->fetchColumn();

        if ($result !== false && $result !== '') {
            $enabledLinks = json_decode($result, true);
            if (!is_array($enabledLinks)) {
                $enabledLinks = null;
            }
        }
    } catch (Exception $e) {
        // If settings can't be loaded, show all links
        $enabledLinks = null;
    }

    return $enabledLinks;
}

/**
 * Navigation links available to sales representatives.
 *
 * @return array<string, array{label:string,href:string}>
 */
function sales_portal_nav_links(): array
{
    // Load translations
    require_once __DIR__ . '/lang.php';

    // Determine if we're in a subdirectory (like /pages/sales/orders/)
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $inSubdir = strpos($scriptPath, '/pages/sales/orders/') !== false;
    $prefix = $inSubdir ? '../' : '';

    // Get enabled links from settings
    $enabledLinks = sales_portal_get_enabled_links();

    // Arabic navigation labels for sales portal
    $allLinks = [
        'dashboard' => [
            'label' => '🏠 لوحة التحكم',
            'href' => $prefix . 'dashboard.php',
        ],
        'orders_van' => [
            'label' => '🚚 إنشاء بيع جديد',
            'href' => $prefix . 'van_stock_sales.php',
        ],
        'orders_cart' => [
            'label' => '🛒 بيع سريع',
            'href' => $prefix . 'van_stock_cart.php',
        ],
        'orders' => [
            'label' => '📋 طلباتي',
            'href' => $prefix . 'orders.php',
        ],
        'users' => [
            'label' => '👥 زبائني',
            'href' => $prefix . 'users.php',
        ],
        'add_customer' => [
            'label' => '➕ إضافة زبون جديد',
            'href' => $prefix . 'add_customer.php',
        ],
        'van_stock' => [
            'label' => '📦 مخزون السيارة',
            'href' => $prefix . 'van_stock.php',
        ],
        'accept_orders' => [
            'label' => '📥 قبول الطلبات',
            'href' => $prefix . 'accept_orders.php',
        ],
        'notifications' => [
            'label' => '🔔 الإشعارات',
            'href' => $prefix . 'notifications.php',
        ],
        'stock_auth' => [
            'label' => '🔐 تصاريح المخزون',
            'href' => $prefix . 'van_loading_auth.php',
        ],
        'stock_return' => [
            'label' => '↩️ إرجاع المخزون',
            'href' => $prefix . 'stock_return.php',
        ],
        'van_restock' => [
            'label' => '🚚 تعبئة السيارة',
            'href' => $prefix . 'van_restock.php',
        ],
        'customer_returns' => [
            'label' => '🔄 مرتجعات الزبائن',
            'href' => $prefix . 'customer_returns.php',
        ],
        'warehouse_stock' => [
            'label' => '🏭 مخزون المستودع',
            'href' => $prefix . 'warehouse_stock.php',
        ],
        'orders_request' => [
            'label' => '🏢 طلب من الشركة',
            'href' => $prefix . 'company_order_request.php',
        ],
        'invoices' => [
            'label' => '💵 الفواتير',
            'href' => $prefix . 'invoices.php',
        ],
        'receivables' => [
            'label' => '💰 التحصيلات',
            'href' => $prefix . 'receivables.php',
        ],
        'expenses' => [
            'label' => '💵 مصاريفي',
            'href' => $prefix . 'expenses.php',
        ],
        'products' => [
            'label' => '📦 جميع المنتجات',
            'href' => $prefix . 'products.php',
        ],
        'analytics' => [
            'label' => '📊 أدائي',
            'href' => $prefix . 'analytics.php',
        ],
    ];

    // If no settings configured, return all links
    if ($enabledLinks === null) {
        return $allLinks;
    }

    // Filter to only enabled links
    $filteredLinks = [];
    foreach ($enabledLinks as $linkKey) {
        if (isset($allLinks[$linkKey])) {
            $filteredLinks[$linkKey] = $allLinks[$linkKey];
        }
    }

    return $filteredLinks;
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

    // Load language helpers - Arabic is the default language for sales portal
    require_once __DIR__ . '/lang.php';

    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>', $escTitle, '</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700&display=swap" rel="stylesheet">';
    echo $extraHead;
    echo '<link rel="stylesheet" href="../css/app.css?v=2">';
    echo '<style>';
    echo ':root{--bg:#f3f4f6;--bg-panel:#ffffff;--bg-panel-alt:#f9fafc;--text:#111827;--muted:#6b7280;';
    echo '--accent:#0ea5e9;--accent-2:#06b6d4;--border:#e5e7eb;--sales-gradient:linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%);}';
    echo '*{box-sizing:border-box;}';
    echo 'body{margin:0;font-family:"Tajawal","Segoe UI",system-ui,-apple-system,sans-serif;';
    echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;}a{color:var(--accent);text-decoration:none;}';
    echo 'a:hover{text-decoration:underline;}';
    echo '.layout{display:flex;flex:1;}.sidebar{width:260px;background:var(--sales-gradient);border-right:none;padding:28px 20px;display:flex;';
    echo 'flex-direction:column;gap:32px;box-shadow:-4px 0 12px rgba(0,0,0,0.08);position:fixed;top:0;right:0;bottom:0;overflow-y:auto;}';
    echo '.brand{font-size:1.7rem;font-weight:800;letter-spacing:.04em;color:#ffffff;text-shadow:0 2px 4px rgba(0,0,0,0.1);}';
    echo '.brand small{display:block;font-size:0.75rem;font-weight:400;opacity:0.9;margin-top:4px;letter-spacing:0.1em;}';
    echo '.nav-links{display:flex;flex-direction:column;gap:6px;}';
    echo '.nav-links a{padding:12px 14px;border-radius:10px;font-size:0.92rem;color:rgba(255,255,255,0.85);transition:all .2s;font-weight:500;}';
    echo '.nav-links a:hover{background:rgba(255,255,255,0.15);color:#ffffff;text-decoration:none;transform:translateX(-4px);}';
    echo '.nav-links a.active{background:#ffffff;color:var(--accent);font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.15);}';
    echo '.user-card{margin-top:auto;padding:16px;border-radius:12px;border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.1);';
    echo 'font-size:0.9rem;color:rgba(255,255,255,0.85);backdrop-filter:blur(10px);}';
    echo '.user-card strong{display:block;font-size:1rem;color:#ffffff;font-weight:600;}';
    echo '.lang-switcher{margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.2);text-align:center;}';
    echo '.lang-switcher a{display:inline-block;padding:8px 12px;background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.9);';
    echo 'border-radius:8px;font-size:0.85rem;font-weight:600;text-decoration:none;transition:all 0.2s;border:1px solid rgba(255,255,255,0.2);}';
    echo '.lang-switcher a:hover{background:rgba(255,255,255,0.2);color:#ffffff;}';
    echo '.main{flex:1;padding:36px;display:flex;flex-direction:column;gap:24px;margin-right:260px;}';
    echo '.page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:18px;}';
    echo '.page-header h1{margin:0;font-size:2rem;}';
    echo '.page-header p{margin:4px 0 0;color:var(--muted);font-size:0.95rem;}';
    echo '.card{background:var(--bg-panel);border-radius:16px;padding:28px;border:1px solid var(--border);box-shadow:0 24px 40px rgba(15,23,42,0.08);}';
    echo '.card h2{margin:0 0 12px;font-size:1.4rem;}';
    echo '.card p{margin:0;color:var(--muted);line-height:1.6;}';
    echo '.card ul{margin:0;padding-right:20px;padding-left:0;color:var(--muted);line-height:1.6;}';
    echo '.card ul li{margin-bottom:6px;}';
    echo '.actions{display:flex;gap:12px;flex-wrap:wrap;}';
    echo '.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;';
    echo 'background:#fff;border:1px solid var(--border);color:var(--text);font-weight:600;text-decoration:none;}';
    echo '.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}';
    echo '.btn-primary:hover{box-shadow:0 0 0 4px rgba(14,165,233,0.18);}';

    // RTL is now the default - no additional rules needed
    echo '.page-header{text-align:right;}';

    // Mobile responsive styles with hamburger menu
    echo '.hamburger{display:none;background:none;border:none;cursor:pointer;padding:8px;z-index:1001;}';
    echo '.hamburger span{display:block;width:24px;height:3px;background:#fff;margin:5px 0;border-radius:2px;transition:0.3s;}';
    echo '.sidebar-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:999;}';
    echo '.sidebar-close{display:none;position:absolute;top:16px;left:16px;background:rgba(255,255,255,0.2);border:none;';
    echo 'color:#fff;font-size:1.5rem;width:36px;height:36px;border-radius:50%;cursor:pointer;}';
    echo '@media (max-width:900px){';
    echo 'body{overflow-x:hidden;}';
    echo '.layout{overflow-x:hidden;}';
    echo '.hamburger{display:block;position:fixed;top:16px;right:16px;z-index:1001;background:var(--accent);border-radius:8px;padding:10px;}';
    echo '.sidebar{position:fixed;top:0;right:0;bottom:0;transform:translateX(100%);transition:transform 0.3s ease;z-index:1000;width:280px;overflow-y:auto;}';
    echo '.sidebar.open{transform:translateX(0);}';
    echo '.sidebar-overlay.open{display:block;}';
    echo '.sidebar-close{display:block;}';
    echo '.main{margin-right:0;padding:80px 20px 20px 20px;width:100%;}';
    echo '.page-header h1{font-size:1.5rem;}';
    echo '.page-header{flex-direction:column;align-items:flex-start;}';
    echo '}';
    echo '@media (max-width:480px){';
    echo '.main{padding:70px 12px 12px 12px;}';
    echo '.card{padding:16px;border-radius:12px;}';
    echo '}';
    // Tablet specific improvements (600-900px)
    echo '@media (min-width:600px) and (max-width:900px){';
    echo '.main{padding:80px 24px 24px 24px;}';
    echo '.page-header{gap:12px;}';
    echo '.actions{gap:8px;}';
    echo '.btn{padding:8px 12px;font-size:0.9rem;}';
    echo '}';
    echo '</style></head><body class="theme-light">';

    // Hamburger button and overlay
    echo '<button class="hamburger" onclick="toggleSidebar()" aria-label="Toggle menu"><span></span><span></span><span></span></button>';
    echo '<div class="sidebar-overlay" onclick="toggleSidebar()"></div>';

    echo '<div class="layout"><aside class="sidebar">';
    echo '<button class="sidebar-close" onclick="toggleSidebar()">&times;</button>';
    echo '<div class="brand">سلامة تولز<small>بوابة المبيعات</small></div><nav class="nav-links">';

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
        echo '<strong>مندوب مبيعات</strong>';
    }

    if ($displayRole) {
        // Translate common roles to Arabic
        $roleTranslations = [
            'Sales Rep' => 'مندوب مبيعات',
            'Admin' => 'مدير',
            'Warehouse' => 'مستودع',
            'Manager' => 'مدير',
        ];
        $arabicRole = $roleTranslations[$displayRole] ?? $displayRole;
        echo '<span>', htmlspecialchars($arabicRole, ENT_QUOTES, 'UTF-8'), '</span>';
    } else {
        echo '<span>تم تسجيل الدخول</span>';
    }

    // Logout button
    $logoutPath = $inSubdir ? '../../logout.php' : '../logout.php';
    echo '<a href="', htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8'), '" style="display: inline-block; margin-top: 12px; padding: 8px 12px; background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-radius: 8px; text-align: center; font-weight: 600; font-size: 0.85rem; border: 1px solid rgba(239, 68, 68, 0.3); text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background=\'rgba(239, 68, 68, 0.25)\'; this.style.color=\'#ffffff\';" onmouseout="this.style.background=\'rgba(239, 68, 68, 0.15)\'; this.style.color=\'#fca5a5\';">🚪 تسجيل الخروج</a>';

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
    echo '</main></div>';
    echo '<script>';
    echo 'function toggleSidebar(){';
    echo '  document.querySelector(".sidebar").classList.toggle("open");';
    echo '  document.querySelector(".sidebar-overlay").classList.toggle("open");';
    echo '  document.body.style.overflow = document.querySelector(".sidebar").classList.contains("open") ? "hidden" : "";';
    echo '}';
    // Close sidebar when clicking a nav link on mobile
    echo 'document.querySelectorAll(".nav-links a").forEach(function(link){';
    echo '  link.addEventListener("click", function(){';
    echo '    if(window.innerWidth <= 900){';
    echo '      document.querySelector(".sidebar").classList.remove("open");';
    echo '      document.querySelector(".sidebar-overlay").classList.remove("open");';
    echo '      document.body.style.overflow = "";';
    echo '    }';
    echo '  });';
    echo '});';
    echo '</script>';
    echo '</body></html>';
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
