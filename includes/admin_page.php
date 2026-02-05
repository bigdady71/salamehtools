<?php

declare(strict_types=1);

/**
 * Helper utilities for rendering admin pages with a shared layout.
 */
if (!function_exists('admin_nav_links')) {
    function admin_nav_links(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => '📊'],
            'users' => ['label' => 'Users', 'href' => 'users.php', 'icon' => '👥'],
            'products' => ['label' => 'Products', 'href' => 'products.php', 'icon' => '📦'],
            'orders' => ['label' => 'Orders', 'href' => 'orders.php', 'icon' => '🛒'],
            'invoices' => ['label' => 'Invoices', 'href' => 'invoices.php', 'icon' => '📄'],
            'invoice_approvals' => ['label' => 'Invoice Approvals', 'href' => 'invoice_approvals.php', 'icon' => '✏️'],
            'customers' => ['label' => 'Customers', 'href' => 'customers.php', 'icon' => '🏪'],
            'sales_reps' => ['label' => 'Sales Reps', 'href' => 'sales_reps.php', 'icon' => '👔'],
            'van_stock' => ['label' => 'Van Stock', 'href' => 'van_stock_overview.php', 'icon' => '🚚'],
            'stock_adjustments' => ['label' => 'Rep Stock Auth', 'href' => 'sales_rep_stock_adjustment.php', 'icon' => '✅'],
            'receivables' => ['label' => 'Receivables', 'href' => 'receivables.php', 'icon' => '💰'],
            'expenses' => ['label' => 'Expenses', 'href' => 'expenses.php', 'icon' => '💸'],
            'cash_refunds' => ['label' => 'Cash Refunds', 'href' => 'cash_refund_approvals.php', 'icon' => '↩️'],
            'warehouse' => ['label' => 'Warehouse', 'href' => 'warehouse_stock.php', 'icon' => '🏭'],
            'analytics' => ['label' => 'Analytics', 'href' => 'analytics.php', 'icon' => '📈'],
            'stats' => ['label' => 'Statistics', 'href' => 'stats.php', 'icon' => '📉'],
            'settings' => ['label' => 'Settings', 'href' => 'settings.php', 'icon' => '⚙️'],
        ];
    }
}

if (!function_exists('admin_render_layout_start')) {
    function admin_render_layout_start(array $options = []): void
{
    $title = (string)($options['title'] ?? 'Admin');
    $heading = (string)($options['heading'] ?? $title);
    $subtitle = $options['subtitle'] ?? null;
    $active = (string)($options['active'] ?? '');
    $user = $options['user'] ?? null;
    $extraHead = (string)($options['extra_head'] ?? '');

    $navItems = admin_nav_links();
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
    require_once __DIR__ . '/assets.php';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>', $escTitle, '</title>';
    echo $extraHead;
    echo '<link rel="stylesheet" href="', asset_url('/css/app.css'), '">';
    ?>
    <style>
    :root {
        --bg: #f8fafc;
        --bg-panel: #ffffff;
        --bg-panel-alt: #f1f5f9;
        --text: #0f172a;
        --muted: #64748b;
        --accent: #3b82f6;
        --accent-hover: #2563eb;
        --accent-light: rgba(59, 130, 246, 0.1);
        --border: #e2e8f0;
        --sidebar-width: 260px;
        --sidebar-collapsed-width: 72px;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: none; color: var(--accent-hover); }

    .layout {
        display: flex;
        min-height: 100vh;
    }

    /* Sidebar Styles */
    .sidebar {
        width: var(--sidebar-width);
        background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
        padding: 0;
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 100;
        transition: width var(--transition), transform var(--transition);
        overflow: hidden;
        box-shadow: var(--shadow-lg);
    }

    .sidebar.collapsed {
        width: var(--sidebar-collapsed-width);
    }

    .sidebar-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        min-height: 72px;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 1.25rem;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        transition: opacity var(--transition);
    }

    .brand-icon {
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, var(--accent) 0%, #8b5cf6 100%);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }

    .brand-text {
        transition: opacity var(--transition), width var(--transition);
    }

    .sidebar.collapsed .brand-text {
        opacity: 0;
        width: 0;
    }

    .sidebar-toggle {
        width: 32px;
        height: 32px;
        border: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #fff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background var(--transition), transform var(--transition);
        flex-shrink: 0;
    }

    .sidebar-toggle:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .sidebar.collapsed .sidebar-toggle {
        transform: rotate(180deg);
    }

    .nav-links {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 16px 12px;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .nav-links::-webkit-scrollbar {
        width: 4px;
    }

    .nav-links::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 4px;
    }

    .nav-links a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.7);
        transition: all var(--transition);
        white-space: nowrap;
        text-decoration: none;
    }

    .nav-links a:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }

    .nav-links a.active {
        background: var(--accent);
        color: #fff;
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    .nav-icon {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .nav-label {
        transition: opacity var(--transition), width var(--transition);
        overflow: hidden;
    }

    .sidebar.collapsed .nav-label {
        opacity: 0;
        width: 0;
    }

    .sidebar.collapsed .nav-links a {
        justify-content: center;
        padding: 12px;
    }

    /* User Card */
    .user-card {
        margin: auto 12px 12px;
        padding: 14px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.9);
        transition: all var(--transition);
        overflow: hidden;
    }

    .user-card-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
        color: #fff;
        flex-shrink: 0;
    }

    .user-info {
        flex: 1;
        min-width: 0;
        transition: opacity var(--transition), width var(--transition);
    }

    .sidebar.collapsed .user-info {
        opacity: 0;
        width: 0;
        overflow: hidden;
    }

    .user-info strong {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-info span {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.6);
    }

    .logout-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        margin-top: 12px;
        padding: 10px 12px;
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.85rem;
        border: 1px solid rgba(239, 68, 68, 0.3);
        text-decoration: none;
        transition: all var(--transition);
    }

    .logout-btn:hover {
        background: rgba(239, 68, 68, 0.3);
        color: #fecaca;
    }

    .sidebar.collapsed .logout-btn span {
        display: none;
    }

    .sidebar.collapsed .user-card {
        padding: 10px;
    }

    .sidebar.collapsed .user-card-content {
        justify-content: center;
    }

    .sidebar.collapsed .logout-btn {
        margin-top: 8px;
        padding: 10px;
    }

    /* Main Content */
    .main {
        flex: 1;
        padding: 32px 40px;
        margin-left: var(--sidebar-width);
        display: flex;
        flex-direction: column;
        gap: 24px;
        transition: margin-left var(--transition);
        min-height: 100vh;
    }

    body.sidebar-collapsed .main {
        margin-left: var(--sidebar-collapsed-width);
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 18px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 8px;
    }

    .page-header h1 {
        margin: 0;
        font-size: 1.875rem;
        font-weight: 700;
        color: var(--text);
        letter-spacing: -0.025em;
    }

    .page-header p {
        margin: 6px 0 0;
        color: var(--muted);
        font-size: 0.95rem;
    }

    /* Cards */
    .card {
        background: var(--bg-panel);
        border-radius: 16px;
        padding: 24px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        transition: box-shadow var(--transition);
    }

    .card:hover {
        box-shadow: var(--shadow-md);
    }

    .card h2 { margin: 0 0 12px; font-size: 1.25rem; font-weight: 600; }
    .card p { margin: 0; color: var(--muted); line-height: 1.6; }
    .card ul { margin: 0; padding-left: 20px; color: var(--muted); line-height: 1.6; }
    .card ul li { margin-bottom: 6px; }

    /* Actions & Buttons */
    .actions { display: flex; gap: 12px; flex-wrap: wrap; }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 18px;
        border-radius: 10px;
        background: var(--bg-panel);
        border: 1px solid var(--border);
        color: var(--text);
        font-weight: 600;
        font-size: 0.9rem;
        text-decoration: none;
        cursor: pointer;
        transition: all var(--transition);
    }

    .btn:hover {
        background: var(--bg-panel-alt);
        border-color: #cbd5e1;
    }

    .btn-primary {
        background: var(--accent);
        border-color: var(--accent);
        color: #fff;
    }

    .btn-primary:hover {
        background: var(--accent-hover);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .btn-success {
        background: #10b981;
        border-color: #10b981;
        color: #fff;
    }

    .btn-success:hover {
        background: #059669;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-info {
        background: #0ea5e9;
        border-color: #0ea5e9;
        color: #fff;
    }

    .btn-info:hover {
        background: #0284c7;
        box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
    }

    .btn-warning {
        background: #f59e0b;
        border-color: #f59e0b;
        color: #fff;
    }

    .btn-warning:hover {
        background: #d97706;
    }

    .btn-secondary {
        background: var(--bg-panel-alt);
        border-color: var(--border);
        color: var(--muted);
    }

    .btn-secondary:hover {
        background: #e2e8f0;
        color: var(--text);
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 0.85rem;
        border-radius: 8px;
    }

    /* Mobile Overlay */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 99;
        opacity: 0;
        transition: opacity var(--transition);
    }

    .sidebar-overlay.active {
        opacity: 1;
    }

    /* Mobile Toggle Button */
    .mobile-menu-btn {
        display: none;
        position: fixed;
        top: 16px;
        left: 16px;
        z-index: 101;
        width: 44px;
        height: 44px;
        border: none;
        background: var(--accent);
        border-radius: 12px;
        color: #fff;
        cursor: pointer;
        font-size: 1.25rem;
        box-shadow: var(--shadow-md);
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .main {
            padding: 24px;
        }
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
            width: var(--sidebar-width);
        }

        .sidebar.mobile-open {
            transform: translateX(0);
        }

        .sidebar.collapsed {
            width: var(--sidebar-width);
        }

        .sidebar.collapsed .brand-text,
        .sidebar.collapsed .nav-label,
        .sidebar.collapsed .user-info {
            opacity: 1;
            width: auto;
        }

        .sidebar.collapsed .nav-links a {
            justify-content: flex-start;
            padding: 12px 14px;
        }

        .sidebar.collapsed .logout-btn span {
            display: inline;
        }

        .main {
            margin-left: 0 !important;
            padding: 80px 16px 24px;
        }

        .mobile-menu-btn {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-overlay {
            display: block;
        }

        .sidebar-toggle {
            display: none;
        }

        .page-header h1 {
            font-size: 1.5rem;
        }
    }
    </style>
    <?php
    echo '</head><body class="theme-light">';
    echo '<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>';
    echo '<button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileSidebar()">☰</button>';
    echo '<div class="layout">';
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-header">';
    echo '<div class="brand"><span class="brand-icon">🔧</span><span class="brand-text">Salameh Tools</span></div>';
    echo '<button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle sidebar">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>';
    echo '</button>';
    echo '</div>';
    echo '<nav class="nav-links">';

    foreach ($navItems as $slug => $item) {
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
        $icon = $item['icon'] ?? '📄';
        $classes = 'nav-link' . ($slug === $active ? ' active' : '');
        echo '<a class="', $classes, '" href="', $href, '" title="', $label, '">';
        echo '<span class="nav-icon">', $icon, '</span>';
        echo '<span class="nav-label">', $label, '</span>';
        echo '</a>';
    }

    echo '</nav>';
    echo '<div class="user-card">';
    echo '<div class="user-card-content">';

    $initials = 'AD';
    if ($displayName !== null && $displayName !== '') {
        $nameParts = explode(' ', $displayName);
        $initials = strtoupper(substr($nameParts[0], 0, 1));
        if (count($nameParts) > 1) {
            $initials .= strtoupper(substr($nameParts[1], 0, 1));
        }
    }

    echo '<div class="user-avatar">', $initials, '</div>';
    echo '<div class="user-info">';

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

    echo '</div></div>';
    echo '<a href="../logout.php" class="logout-btn">🚪 <span>Logout</span></a>';
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

if (!function_exists('admin_render_layout_end')) {
    function admin_render_layout_end(): void
    {
        ?>
        <script>
        // Sidebar toggle functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const body = document.body;
            sidebar.classList.toggle('collapsed');
            body.classList.toggle('sidebar-collapsed');
            
            // Save state to localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
        }

        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
            
            // Prevent body scroll when sidebar is open
            if (sidebar.classList.contains('mobile-open')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        // Restore sidebar state on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedState = localStorage.getItem('sidebarCollapsed');
            const sidebar = document.getElementById('sidebar');
            const body = document.body;
            
            // Only apply collapsed state on desktop
            if (window.innerWidth > 768 && savedState === 'true') {
                sidebar.classList.add('collapsed');
                body.classList.add('sidebar-collapsed');
            }
            
            // Close mobile sidebar on resize to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    const overlay = document.getElementById('sidebarOverlay');
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
            
            // Close sidebar on nav link click (mobile)
            const navLinks = document.querySelectorAll('.nav-links a');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        toggleMobileSidebar();
                    }
                });
            });
        });

        // Close mobile sidebar with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar.classList.contains('mobile-open')) {
                    toggleMobileSidebar();
                }
            }
        });
        </script>
        <?php
        echo '</main></div></body></html>';
    }
}

/**
 * Renders flash messages directly below the page header.
 *
 * @param array<int, array{type:string,message:string,title:?string,lines:array<int,string>,list:array<int,string>,dismissible:bool}> $flashes
 */
if (!function_exists('admin_render_flashes')) {
    function admin_render_flashes(array $flashes): void
{
    if (!$flashes) {
        return;
    }

    $flashStyles = [
        'success' => ['bg' => '#ecfdf5', 'border' => '#10b981', 'text' => '#065f46', 'icon' => '✓'],
        'error' => ['bg' => '#fef2f2', 'border' => '#ef4444', 'text' => '#991b1b', 'icon' => '✕'],
        'warning' => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'text' => '#92400e', 'icon' => '⚠'],
        'info' => ['bg' => '#eff6ff', 'border' => '#3b82f6', 'text' => '#1e40af', 'icon' => 'ℹ'],
    ];

    echo '<div class="flash-stack" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px;">';

    foreach ($flashes as $flash) {
        $type = $flash['type'] ?? 'info';
        $style = $flashStyles[$type] ?? $flashStyles['info'];
        $dismissible = !empty($flash['dismissible']);
        $title = isset($flash['title']) && $flash['title'] !== null ? trim((string)$flash['title']) : '';
        $message = trim((string)($flash['message'] ?? ''));
        $lines = isset($flash['lines']) && is_array($flash['lines']) ? $flash['lines'] : [];
        $list = isset($flash['list']) && is_array($flash['list']) ? $flash['list'] : [];

        echo '<div class="flash" role="alert" style="';
        echo 'display: flex; align-items: flex-start; gap: 12px; padding: 16px; ';
        echo 'background: ', $style['bg'], '; border: 1px solid ', $style['border'], '; ';
        echo 'border-radius: 12px; color: ', $style['text'], '; font-size: 0.9rem; ';
        echo 'animation: slideIn 0.3s ease-out;">';

        echo '<span style="font-size: 1.1rem; line-height: 1;">', $style['icon'], '</span>';
        echo '<div style="flex: 1;">';

        if ($title !== '') {
            echo '<div style="font-weight: 700; margin-bottom: 4px;">', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), '</div>';
        }

        if ($message !== '') {
            echo '<div>', htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), '</div>';
        }

        if ($lines) {
            echo '<div style="margin-top: 8px;">';
            foreach ($lines as $line) {
                if ($line === null || $line === '') {
                    continue;
                }
                echo '<div>', htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8'), '</div>';
            }
            echo '</div>';
        }

        if ($list) {
            echo '<ul style="margin: 8px 0 0; padding-left: 20px;">';
            foreach ($list as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                echo '<li>', htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8'), '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';

        if ($dismissible) {
            echo '<button type="button" onclick="this.parentElement.remove()" style="';
            echo 'background: none; border: none; font-size: 1.25rem; cursor: pointer; ';
            echo 'color: ', $style['text'], '; opacity: 0.7; padding: 0; line-height: 1;"';
            echo ' aria-label="Dismiss message">&times;</button>';
        }

        echo '</div>';
    }

    echo '</div>';
    echo '<style>@keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }</style>';
    }
}
