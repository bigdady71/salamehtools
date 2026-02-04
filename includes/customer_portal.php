<?php

declare(strict_types=1);

/**
 * Customer Portal Layout and Authentication Functions
 * Modern, responsive design with collapsible sidebar
 */

/**
 * Ensure the current session belongs to a customer and return the customer data.
 *
 * @return array<string, mixed>
 */
function customer_portal_bootstrap(): array
{
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Calculate base path dynamically (works on both local and Hostinger)
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = '';
    $pos = strpos($scriptPath, '/pages/');
    if ($pos !== false) {
        $basePath = substr($scriptPath, 0, $pos);
    }
    $loginUrl = $basePath . '/pages/login.php';

    // Check if user is logged in and has customer role
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role'])) {
        header('Location: ' . $loginUrl);
        exit;
    }

    $userRole = $_SESSION['user']['role'];

    // Customer role can be 'customer', 'viewer', or empty string
    if ($userRole !== 'customer' && $userRole !== 'viewer' && $userRole !== '') {
        header('Location: ' . $loginUrl);
        exit;
    }

    $userId = (int)$_SESSION['user']['id'];
    $pdo = db();

    if ($userRole === 'customer') {
        $customerId = $userId;
    } else {
        $customerLookup = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
        $customerLookup->execute([$userId]);
        $customerRow = $customerLookup->fetch(PDO::FETCH_ASSOC);

        if (!$customerRow) {
            session_destroy();
            header('Location: ' . $loginUrl . '?error=no_customer_record');
            exit;
        }
        $customerId = (int)$customerRow['id'];
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.name,
            c.phone,
            c.location,
            c.shop_type,
            c.customer_tier,
            c.account_balance_lbp,
            c.assigned_sales_rep_id,
            c.last_login_at,
            u.name as sales_rep_name,
            u.email as sales_rep_email,
            u.phone as sales_rep_phone
        FROM customers c
        LEFT JOIN users u ON u.id = c.assigned_sales_rep_id
        WHERE c.id = ? AND c.login_enabled = 1 AND c.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        session_destroy();
        header('Location: ' . $loginUrl . '?error=account_disabled');
        exit;
    }

    return $customer;
}

/**
 * Navigation links available to customers.
 */
function customer_portal_nav_links(): array
{
    return [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'home'],
        'products' => ['label' => 'Products', 'href' => 'products.php', 'icon' => 'grid'],
        'cart' => ['label' => 'Cart', 'href' => 'cart.php', 'icon' => 'cart'],
        'favorites' => ['label' => 'Favorites', 'href' => 'favorites.php', 'icon' => 'heart'],
        'orders' => ['label' => 'Orders', 'href' => 'orders.php', 'icon' => 'package'],
        'invoices' => ['label' => 'Invoices', 'href' => 'invoices.php', 'icon' => 'file'],
        'payments' => ['label' => 'Payments', 'href' => 'payments.php', 'icon' => 'credit'],
        'profile' => ['label' => 'Profile', 'href' => 'profile.php', 'icon' => 'user'],
        'contact' => ['label' => 'Contact', 'href' => 'contact.php', 'icon' => 'phone'],
    ];
}

/**
 * Get SVG icon by name
 */
function customer_portal_icon(string $name): string
{
    $icons = [
        'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'grid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'cart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
        'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        'package' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'file' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'credit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'phone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'chevron' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/**
 * Renders the start of a customer portal page layout.
 */
function customer_portal_render_layout_start(array $options = []): void
{
    $title = (string)($options['title'] ?? 'Customer Portal');
    $heading = (string)($options['heading'] ?? $title);
    $subtitle = $options['subtitle'] ?? null;
    $active = (string)($options['active'] ?? '');
    $customer = $options['customer'] ?? null;
    $extraHead = (string)($options['extra_head'] ?? '');

    $navItems = customer_portal_nav_links();
    if (isset($options['nav_links']) && is_array($options['nav_links']) && $options['nav_links']) {
        $navItems = $options['nav_links'];
    }

    $escTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $escHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $escSubtitle = $subtitle !== null ? htmlspecialchars((string)$subtitle, ENT_QUOTES, 'UTF-8') : null;

    $displayName = 'Customer';
    $shopType = null;
    if (is_array($customer)) {
        $name = trim((string)($customer['name'] ?? ''));
        $displayName = $name !== '' ? $name : 'Customer';
        $shopType = trim((string)($customer['shop_type'] ?? '')) ?: null;
    }

    require_once __DIR__ . '/assets.php';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<title>', $escTitle, ' | Salameh Tools</title>';
    echo '<meta name="theme-color" content="#DC2626">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo $extraHead;

    // Main CSS
    echo '<style>';
    echo '
:root {
    --primary: #DC2626;
    --primary-dark: #B91C1C;
    --primary-light: #FEE2E2;
    --bg: #F8FAFC;
    --bg-card: #FFFFFF;
    --bg-sidebar: linear-gradient(180deg, #1F2937 0%, #111827 100%);
    --text: #1E293B;
    --text-muted: #64748B;
    --text-light: #94A3B8;
    --border: #E2E8F0;
    --success: #10B981;
    --warning: #F59E0B;
    --danger: #EF4444;
    --sidebar-width: 260px;
    --sidebar-collapsed: 72px;
    --header-height: 64px;
    --radius: 12px;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
    --transition: 0.2s ease;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

html { 
    scroll-behavior: smooth;
    -webkit-text-size-adjust: 100%;
}

body {
    font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
    overflow-x: hidden;
}

/* Sidebar */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sidebar-width);
    height: 100vh;
    background: var(--bg-sidebar);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: transform var(--transition), width var(--transition);
    overflow: hidden;
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 72px;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: white;
}

.sidebar-logo {
    width: 40px;
    height: 40px;
    background: var(--primary);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.2rem;
    flex-shrink: 0;
}

.sidebar-title {
    font-size: 1.1rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
}

.sidebar-title small {
    display: block;
    font-size: 0.7rem;
    font-weight: 500;
    color: rgba(255,255,255,0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sidebar-toggle {
    display: none;
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.7);
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: var(--transition);
}

.sidebar-toggle:hover {
    background: rgba(255,255,255,0.1);
    color: white;
}

.sidebar-toggle svg {
    width: 20px;
    height: 20px;
}

/* User Info */
.sidebar-user {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.user-avatar {
    width: 44px;
    height: 44px;
    background: var(--primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    color: white;
    margin-bottom: 10px;
}

.user-name {
    font-size: 0.95rem;
    font-weight: 600;
    color: white;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-type {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.6);
    margin-top: 2px;
}

/* Navigation */
.sidebar-nav {
    flex: 1;
    padding: 16px 12px;
    overflow-y: auto;
    overflow-x: hidden;
    min-height: 0; /* Important for flex overflow */
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    border-radius: 10px;
    margin-bottom: 4px;
    transition: var(--transition);
    font-size: 0.9rem;
    font-weight: 500;
    white-space: nowrap;
}

.nav-item:hover {
    background: rgba(255,255,255,0.08);
    color: white;
}

.nav-item.active {
    background: var(--primary);
    color: white;
    font-weight: 600;
}

.nav-item svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

.nav-item span {
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Sidebar Footer - Always visible at bottom */
.sidebar-footer {
    padding: 16px;
    border-top: 1px solid rgba(255,255,255,0.1);
    margin-top: auto;
    flex-shrink: 0;
    background: linear-gradient(180deg, #1F2937 0%, #111827 100%);
}

.logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 12px;
    background: rgba(239,68,68,0.15);
    color: #FCA5A5;
    border: none;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: var(--transition);
}

.logout-btn:hover {
    background: rgba(239,68,68,0.25);
    color: #FEE2E2;
}

.logout-btn svg {
    width: 18px;
    height: 18px;
}

/* Main Content */
.main-wrapper {
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    transition: margin-left var(--transition);
}

/* Mobile Header */
.mobile-header {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: var(--header-height);
    background: white;
    border-bottom: 1px solid var(--border);
    z-index: 999;
    padding: 0 16px;
    align-items: center;
    justify-content: space-between;
}

.mobile-menu-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    transition: var(--transition);
}

.mobile-menu-btn:hover {
    background: var(--primary-light);
    border-color: var(--primary);
}

.mobile-menu-btn svg {
    width: 22px;
    height: 22px;
    color: var(--text);
}

.mobile-brand {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--primary);
}

.mobile-cart-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: var(--primary);
    border: none;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    transition: var(--transition);
}

.mobile-cart-btn:hover {
    background: var(--primary-dark);
}

.mobile-cart-btn svg {
    width: 22px;
    height: 22px;
    color: white;
}

/* Overlay */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    transition: opacity var(--transition);
}

.sidebar-overlay.active {
    display: block;
    opacity: 1;
}

/* Main Content Area */
.main-content {
    padding: 32px;
    max-width: 1400px;
}

/* Page Header */
.page-header {
    margin-bottom: 28px;
}

.page-header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 4px;
}

.page-subtitle {
    font-size: 0.95rem;
    color: var(--text-muted);
}

/* Action Buttons */
.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    font-size: 0.9rem;
    font-weight: 600;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: white;
    color: var(--text);
    cursor: pointer;
    text-decoration: none;
    transition: var(--transition);
    white-space: nowrap;
}

.btn:hover {
    background: var(--bg);
    border-color: var(--text-light);
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
}

/* Cards */
.card {
    background: var(--bg-card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 24px;
    box-shadow: var(--shadow-sm);
}

.card h2 {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 16px;
}

/* Tables */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: var(--radius);
    border: 1px solid var(--border);
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

th, td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

th {
    background: var(--bg);
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

tr:last-child td {
    border-bottom: none;
}

tr:hover td {
    background: var(--bg);
}

/* Alerts */
.alert {
    padding: 14px 18px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #A7F3D0;
}

.alert-error {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

/* Responsive - Tablet Landscape */
@media (max-width: 1024px) and (min-width: 769px) {
    .sidebar {
        width: 220px;
    }
    
    .main-wrapper {
        margin-left: 220px;
    }
    
    .sidebar-nav {
        padding: 12px 8px;
        max-height: calc(100vh - 280px);
        overflow-y: auto;
    }
    
    .nav-item {
        padding: 10px 12px;
        font-size: 0.85rem;
    }
    
    .sidebar-footer {
        padding: 12px;
        position: sticky;
        bottom: 0;
        background: inherit;
    }
    
    .logout-btn {
        padding: 10px;
        font-size: 0.85rem;
    }
    
    .sidebar-user {
        padding: 12px 16px;
    }
    
    .user-avatar {
        width: 38px;
        height: 38px;
        font-size: 1rem;
    }
    
    .user-name {
        font-size: 0.9rem;
    }
}

/* Responsive - Tablet Portrait */
@media (max-width: 1024px) {
    .main-content {
        padding: 24px;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
}

/* Responsive - Mobile */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
    }
    
    .sidebar.open {
        transform: translateX(0);
    }
    
    .main-wrapper {
        margin-left: 0;
    }
    
    .mobile-header {
        display: flex;
    }
    
    .main-content {
        padding: 16px;
        padding-top: calc(var(--header-height) + 16px);
    }
    
    .page-header-top {
        flex-direction: column;
        align-items: stretch;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .header-actions .btn {
        flex: 1;
        justify-content: center;
    }
    
    .page-title {
        font-size: 1.35rem;
    }
    
    .card {
        padding: 16px;
        border-radius: 10px;
    }
    
    .btn {
        padding: 12px 16px;
    }
    
    th, td {
        padding: 10px 12px;
        font-size: 0.85rem;
    }
}

/* Responsive - Small Mobile */
@media (max-width: 480px) {
    .main-content {
        padding: 12px;
        padding-top: calc(var(--header-height) + 12px);
    }
    
    .page-title {
        font-size: 1.2rem;
    }
    
    .page-subtitle {
        font-size: 0.85rem;
    }
    
    .header-actions {
        flex-direction: column;
    }
    
    .header-actions .btn {
        width: 100%;
    }
    
    .card {
        padding: 14px;
    }
    
    .btn {
        font-size: 0.85rem;
        padding: 10px 14px;
    }
}

/* Desktop sidebar toggle */
@media (min-width: 769px) {
    .sidebar-toggle {
        display: flex;
    }
    
    .sidebar.collapsed {
        width: var(--sidebar-collapsed);
    }
    
    .sidebar.collapsed .sidebar-title,
    .sidebar.collapsed .user-name,
    .sidebar.collapsed .user-type,
    .sidebar.collapsed .nav-item span,
    .sidebar.collapsed .logout-btn span {
        display: none;
    }
    
    .sidebar.collapsed .sidebar-header {
        justify-content: center;
        padding: 20px 12px;
    }
    
    .sidebar.collapsed .sidebar-brand {
        justify-content: center;
    }
    
    .sidebar.collapsed .sidebar-user {
        display: flex;
        justify-content: center;
        padding: 16px 12px;
    }
    
    .sidebar.collapsed .user-avatar {
        margin-bottom: 0;
    }
    
    .sidebar.collapsed .nav-item {
        justify-content: center;
        padding: 12px;
    }
    
    .sidebar.collapsed .logout-btn {
        padding: 12px;
    }
    
    .sidebar.collapsed + .main-wrapper {
        margin-left: var(--sidebar-collapsed);
    }
    
    .sidebar-toggle svg {
        transition: transform var(--transition);
    }
    
    .sidebar.collapsed .sidebar-toggle svg {
        transform: rotate(180deg);
    }
}

/* Utility classes */
.text-muted { color: var(--text-muted); }
.text-success { color: var(--success); }
.text-warning { color: var(--warning); }
.text-danger { color: var(--danger); }
.font-bold { font-weight: 700; }
.mt-4 { margin-top: 16px; }
.mb-4 { margin-bottom: 16px; }
';
    echo '</style>';
    echo '</head><body>';

    // Sidebar Overlay (for mobile)
    echo '<div class="sidebar-overlay" id="sidebarOverlay"></div>';

    // Sidebar
    echo '<aside class="sidebar" id="sidebar">';

    // Sidebar Header
    echo '<div class="sidebar-header">';
    echo '<a href="dashboard.php" class="sidebar-brand">';
    echo '<div class="sidebar-logo">ST</div>';
    echo '<div class="sidebar-title">Salameh Tools<small>Customer Portal</small></div>';
    echo '</a>';
    echo '<button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar">';
    echo customer_portal_icon('chevron');
    echo '</button>';
    echo '</div>';

    // User Info
    echo '<div class="sidebar-user">';
    $initials = mb_strtoupper(mb_substr($displayName, 0, 2));
    echo '<div class="user-avatar">', htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'), '</div>';
    echo '<div class="user-name">', htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'), '</div>';
    if ($shopType) {
        echo '<div class="user-type">', htmlspecialchars($shopType, ENT_QUOTES, 'UTF-8'), '</div>';
    }
    echo '</div>';

    // Navigation
    echo '<nav class="sidebar-nav">';
    foreach ($navItems as $slug => $item) {
        $isActive = $slug === $active ? ' active' : '';
        $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $icon = customer_portal_icon($item['icon'] ?? 'grid');
        echo '<a href="', $href, '" class="nav-item', $isActive, '">';
        echo $icon;
        echo '<span>', $label, '</span>';
        echo '</a>';
    }
    echo '</nav>';

    // Sidebar Footer
    echo '<div class="sidebar-footer">';
    echo '<a href="logout.php" class="logout-btn" onclick="return confirm(\'Are you sure you want to logout?\')">';
    echo customer_portal_icon('logout');
    echo '<span>Logout</span>';
    echo '</a>';
    echo '</div>';

    echo '</aside>';

    // Mobile Header
    echo '<header class="mobile-header">';
    echo '<button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">';
    echo customer_portal_icon('menu');
    echo '</button>';
    echo '<span class="mobile-brand">Salameh Tools</span>';
    echo '<a href="cart.php" class="mobile-cart-btn" aria-label="View cart">';
    echo customer_portal_icon('cart');
    echo '</a>';
    echo '</header>';

    // Main Wrapper
    echo '<div class="main-wrapper" id="mainWrapper">';
    echo '<main class="main-content">';

    // Page Header
    echo '<header class="page-header">';
    echo '<div class="page-header-top">';
    echo '<div>';
    echo '<h1 class="page-title">', $escHeading, '</h1>';
    if ($escSubtitle !== null) {
        echo '<p class="page-subtitle">', $escSubtitle, '</p>';
    }
    echo '</div>';

    // Action buttons
    if (!empty($options['actions']) && is_array($options['actions'])) {
        echo '<div class="header-actions">';
        foreach ($options['actions'] as $action) {
            $label = htmlspecialchars((string)($action['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars((string)($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
            $variant = !empty($action['variant']) ? ' btn-' . htmlspecialchars((string)$action['variant'], ENT_QUOTES, 'UTF-8') : '';
            echo '<a class="btn', $variant, '" href="', $href, '">', $label, '</a>';
        }
        echo '</div>';
    }

    echo '</div>';
    echo '</header>';

    // JavaScript for sidebar toggle
    echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("sidebarOverlay");
    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const sidebarToggle = document.getElementById("sidebarToggle");
    const mainWrapper = document.getElementById("mainWrapper");
    
    // Check for saved sidebar state (desktop only)
    const savedState = localStorage.getItem("sidebarCollapsed");
    if (savedState === "true" && window.innerWidth > 768) {
        sidebar.classList.add("collapsed");
    }
    
    // Mobile menu toggle
    function openMobileMenu() {
        sidebar.classList.add("open");
        overlay.classList.add("active");
        document.body.style.overflow = "hidden";
    }
    
    function closeMobileMenu() {
        sidebar.classList.remove("open");
        overlay.classList.remove("active");
        document.body.style.overflow = "";
    }
    
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener("click", function() {
            if (sidebar.classList.contains("open")) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });
    }
    
    if (overlay) {
        overlay.addEventListener("click", closeMobileMenu);
    }
    
    // Desktop sidebar toggle
    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", function() {
            sidebar.classList.toggle("collapsed");
            localStorage.setItem("sidebarCollapsed", sidebar.classList.contains("collapsed"));
        });
    }
    
    // Close mobile menu on nav click
    const navItems = document.querySelectorAll(".nav-item");
    navItems.forEach(function(item) {
        item.addEventListener("click", function() {
            if (window.innerWidth <= 768) {
                closeMobileMenu();
            }
        });
    });
    
    // Handle resize
    let resizeTimer;
    window.addEventListener("resize", function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768) {
                closeMobileMenu();
            }
        }, 100);
    });
});
</script>';
}

/**
 * Renders the end of a customer portal page layout.
 */
function customer_portal_render_layout_end(): void
{
    // Global Loading Spinner
    echo '<div id="globalSpinner" class="global-spinner" style="display:none;">';
    echo '<div class="spinner-backdrop"></div>';
    echo '<div class="spinner-content">';
    echo '<div class="spinner-ring"></div>';
    echo '<p class="spinner-text">Loading...</p>';
    echo '</div>';
    echo '</div>';

    echo '<style>
.global-spinner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}
.spinner-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(4px);
}
.spinner-content {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 32px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.spinner-ring {
    width: 48px;
    height: 48px;
    border: 4px solid #e5e7eb;
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.spinner-text {
    margin: 0;
    color: #374151;
    font-weight: 600;
    font-size: 0.95rem;
}
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
}
.btn-loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin: -8px 0 0 -8px;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}
</style>';

    echo '<script>
function showSpinner(text) {
    var spinner = document.getElementById("globalSpinner");
    if (text) spinner.querySelector(".spinner-text").textContent = text;
    spinner.style.display = "flex";
}
function hideSpinner() {
    document.getElementById("globalSpinner").style.display = "none";
}
function setButtonLoading(btn, loading) {
    if (loading) { btn.classList.add("btn-loading"); btn.disabled = true; }
    else { btn.classList.remove("btn-loading"); btn.disabled = false; }
}
</script>';

    echo '</main></div></body></html>';
}
