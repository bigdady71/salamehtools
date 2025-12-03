<?php

declare(strict_types=1);

/**
 * Customer Portal Layout and Authentication Functions
 * Green-themed portal for customer-facing pages
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

    // Check if user is logged in and has viewer role or empty role (customer)
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role'])) {
        // Redirect to main login
        header('Location: /salamehtools/pages/login.php');
        exit;
    }

    $userRole = $_SESSION['user']['role'];
    if ($userRole !== 'viewer' && $userRole !== '') {
        // Not a customer - redirect to appropriate dashboard
        header('Location: /salamehtools/pages/login.php');
        exit;
    }

    // Get user ID from session
    $userId = (int)$_SESSION['user']['id'];

    // Get database connection
    $pdo = db();

    // Find customer by user_id
    $customerLookup = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $customerLookup->execute([$userId]);
    $customerRow = $customerLookup->fetch(PDO::FETCH_ASSOC);

    if (!$customerRow) {
        // No customer record found for this user
        session_destroy();
        header('Location: /salamehtools/pages/login.php?error=no_customer_record');
        exit;
    }

    $customerId = (int)$customerRow['id'];

    // Fetch customer data from database
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
        // Customer not found or disabled - logout
        session_destroy();
        header('Location: /salamehtools/pages/login.php?error=account_disabled');
        exit;
    }

    return $customer;
}

/**
 * Navigation links available to customers.
 *
 * @return array<string, array{label:string,href:string,icon:string}>
 */
function customer_portal_nav_links(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'href' => 'dashboard.php',
            'icon' => '📊',
        ],
        'products' => [
            'label' => 'Browse Products',
            'href' => 'products.php',
            'icon' => '🛍️',
        ],
        'cart' => [
            'label' => 'Shopping Cart',
            'href' => 'cart.php',
            'icon' => '🛒',
        ],
        'orders' => [
            'label' => 'My Orders',
            'href' => 'orders.php',
            'icon' => '📦',
        ],
        'invoices' => [
            'label' => 'Invoices',
            'href' => 'invoices.php',
            'icon' => '📄',
        ],
        'payments' => [
            'label' => 'Payment History',
            'href' => 'payments.php',
            'icon' => '💳',
        ],
        'statements' => [
            'label' => 'Account Statements',
            'href' => 'statements.php',
            'icon' => '📈',
        ],
        'profile' => [
            'label' => 'My Profile',
            'href' => 'profile.php',
            'icon' => '👤',
        ],
        'contact' => [
            'label' => 'Contact Sales Rep',
            'href' => 'contact.php',
            'icon' => '📞',
        ],
    ];
}

/**
 * Renders the start of a customer portal page layout with a green-themed sidebar.
 *
 * @param array<string, mixed> $options
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
    $displayName = null;
    $shopType = null;

    if (is_array($customer)) {
        $name = trim((string)($customer['name'] ?? ''));
        $displayName = $name !== '' ? $name : 'Customer';
        $shopType = trim((string)($customer['shop_type'] ?? ''));
        if ($shopType === '') {
            $shopType = null;
        }
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes">';
    echo '<title>', $escTitle, ' | Salameh Tools B2B Wholesale Portal</title>';

    // SEO Meta Tags
    echo '<meta name="description" content="Salameh Tools B2B Wholesale Portal - Access exclusive wholesale prices, bulk ordering, and dedicated support for hardware businesses. Shop industrial tools, construction equipment, and professional supplies at competitive wholesale rates.">';
    echo '<meta name="keywords" content="salameh tools wholesale, salameh tools b2b, salameh tools business, wholesale hardware lebanon, b2b tools supplier, bulk hardware orders, industrial tools wholesale, construction equipment wholesale, professional tools b2b, hardware distributor lebanon">';
    echo '<meta name="author" content="Salameh Tools">';
    echo '<meta name="robots" content="index, follow">';

    // Open Graph / Social Media
    echo '<meta property="og:title" content="Salameh Tools - B2B Wholesale Portal">';
    echo '<meta property="og:description" content="Professional wholesale portal for businesses. Access exclusive B2B pricing, bulk orders, and dedicated support.">';
    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:site_name" content="Salameh Tools">';

    // Additional SEO
    echo '<meta name="geo.region" content="LB">';
    echo '<meta name="geo.placename" content="Lebanon">';
    echo '<link rel="canonical" href="https://salamehtools.com/portal/">';

    // JSON-LD Structured Data for SEO - Organization
    echo '<script type="application/ld+json">';
    echo '{';
    echo '"@context": "https://schema.org",';
    echo '"@type": "Organization",';
    echo '"name": "Salameh Tools",';
    echo '"description": "B2B Wholesale Hardware and Tools Supplier in Lebanon",';
    echo '"url": "https://salamehtools.com",';
    echo '"logo": "https://salamehtools.com/images/logo.png",';
    echo '"contactPoint": {';
    echo '"@type": "ContactPoint",';
    echo '"telephone": "+961-XXX-XXXX",';
    echo '"contactType": "Customer Service",';
    echo '"areaServed": "LB",';
    echo '"availableLanguage": ["en", "ar"]';
    echo '},';
    echo '"address": {';
    echo '"@type": "PostalAddress",';
    echo '"addressCountry": "LB",';
    echo '"addressRegion": "Lebanon"';
    echo '},';
    echo '"sameAs": []';
    echo '}';
    echo '</script>';

    // JSON-LD Structured Data - WebSite with SearchAction
    echo '<script type="application/ld+json">';
    echo '{';
    echo '"@context": "https://schema.org",';
    echo '"@type": "WebSite",';
    echo '"name": "Salameh Tools B2B Portal",';
    echo '"url": "https://salamehtools.com",';
    echo '"potentialAction": {';
    echo '"@type": "SearchAction",';
    echo '"target": {';
    echo '"@type": "EntryPoint",';
    echo '"urlTemplate": "https://salamehtools.com/pages/customer/products.php?search={search_term_string}"';
    echo '},';
    echo '"query-input": "required name=search_term_string"';
    echo '}';
    echo '}';
    echo '</script>';

    echo $extraHead;
    echo '<link rel="stylesheet" href="/css/app.css?v=2">';
    echo '<style>';
    // MAKASSI Red/Black/White Theme
    echo ':root{';
    echo '--bg:#f5f5f5;'; // Light gray background
    echo '--bg-panel:#ffffff;';
    echo '--bg-panel-alt:#fef2f2;'; // Very light red tint
    echo '--bg-hover:#fee2e2;'; // Light red hover
    echo '--text:#1a1a1a;'; // Almost black for text
    echo '--text-secondary:#4a4a4a;';
    echo '--muted:#6b7280;';
    echo '--accent:#DC2626;'; // MAKASSI Red (red-600)
    echo '--accent-hover:#B91C1C;'; // Darker red (red-700)
    echo '--accent-light:#FEE2E2;'; // Light red
    echo '--accent-dark:#991B1B;'; // Very dark red (red-800)
    echo '--black:#000000;';
    echo '--border:#e5e7eb;'; // Neutral border
    echo '--border-light:#f3f4f6;';
    echo '--shadow-sm:0 1px 2px 0 rgba(0,0,0,0.05);';
    echo '--shadow:0 4px 6px -1px rgba(0,0,0,0.1);';
    echo '--shadow-lg:0 10px 15px -3px rgba(0,0,0,0.1);';
    echo '--shadow-red:0 10px 25px -5px rgba(220,38,38,0.25);';
    echo '--customer-gradient:linear-gradient(135deg, #DC2626 0%, #991B1B 100%);';
    echo '--customer-gradient-alt:linear-gradient(135deg, #000000 0%, #1a1a1a 100%);';
    echo '}';

    // Base styles
    echo '*{box-sizing:border-box;}';
    echo 'html{overflow-x:hidden;}'; // Prevent horizontal scroll
    echo 'body{margin:0;font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';
    echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;line-height:1.6;overflow-x:hidden;width:100%;max-width:100vw;}';
    echo 'a{color:var(--accent);text-decoration:none;transition:color 0.2s;}';
    echo 'a:hover{color:var(--accent-hover);text-decoration:none;}';

    // Layout
    echo '.layout{display:flex;flex:1;width:100%;max-width:100vw;overflow-x:hidden;}';
    echo '.sidebar{width:270px;background:linear-gradient(180deg, #7f1d1d 0%, #991b1b 50%, #7f1d1d 100%);border-right:3px solid #dc2626;padding:0;display:flex;';
    echo 'flex-direction:column;box-shadow:4px 0 20px rgba(127,29,29,0.4);position:fixed;top:0;left:0;bottom:0;overflow-y:auto;';
    echo 'overscroll-behavior:contain;z-index:100;}';

    // Brand
    echo '.brand{font-size:1.75rem;font-weight:800;letter-spacing:0.02em;color:#ffffff;text-shadow:0 2px 8px rgba(0,0,0,0.3);padding:24px 24px 16px 24px;}';
    echo '.brand small{display:block;font-size:0.7rem;font-weight:500;color:#fecaca;margin-top:6px;letter-spacing:0.15em;text-transform:uppercase;}';

    // Navigation
    echo '.nav-links{display:flex;flex-direction:column;gap:4px;padding:0 16px;flex:1;}';
    echo '.nav-links a{padding:12px 16px;border-radius:12px;font-size:0.925rem;color:rgba(255,255,255,0.85);';
    echo 'transition:all 0.2s ease;font-weight:500;display:flex;align-items:center;gap:10px;text-decoration:none;border:1px solid transparent;}';
    echo '.nav-links a:hover{background:rgba(220,38,38,0.3);color:#ffffff;transform:translateX(4px);border-color:rgba(254,202,202,0.3);text-decoration:none;}';
    echo '.nav-links a.active{background:#dc2626;color:#ffffff;font-weight:600;box-shadow:0 4px 12px rgba(220,38,38,0.5);transform:translateX(2px);border-color:#dc2626;}';

    // Sidebar user section (at top)
    echo '.sidebar-user{padding:16px 20px;border-bottom:1px solid rgba(254,202,202,0.2);margin-bottom:24px;}';
    echo '.user-name{font-size:1.1rem;font-weight:700;color:#ffffff;margin-bottom:4px;}';
    echo '.user-type{font-size:0.85rem;color:#fecaca;opacity:0.9;}';

    // Logout link (simple text at bottom)
    echo '.logout-link{display:block;margin-top:auto;padding:14px 20px;text-align:center;';
    echo 'color:rgba(255,255,255,0.7);font-size:0.9rem;font-weight:500;text-decoration:none;';
    echo 'border-top:1px solid rgba(254,202,202,0.2);transition:all 0.2s ease;}';
    echo '.logout-link:hover{color:#ffffff;background:rgba(239,68,68,0.2);text-decoration:none;}';

    // Main content
    echo '.main{flex:1;padding:40px;display:flex;flex-direction:column;gap:28px;margin-left:270px;max-width:1600px;width:100%;overflow-x:hidden;}';

    // Page header
    echo '.page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:20px;margin-bottom:8px;}';
    echo '.page-header h1{margin:0;font-size:2.25rem;font-weight:700;color:var(--text);letter-spacing:-0.02em;}';
    echo '.page-header p{margin:6px 0 0;color:var(--muted);font-size:1rem;}';

    // Cards
    echo '.card{background:var(--bg-panel);border-radius:20px;padding:32px;border:1px solid var(--border);';
    echo 'box-shadow:var(--shadow);transition:box-shadow 0.2s;}';
    echo '.card:hover{box-shadow:var(--shadow-lg);}';
    echo '.card h2{margin:0 0 16px;font-size:1.5rem;font-weight:700;color:var(--text);}';
    echo '.card h3{margin:0 0 12px;font-size:1.25rem;font-weight:600;color:var(--text);}';
    echo '.card p{margin:0;color:var(--text-secondary);line-height:1.7;}';
    echo '.card ul{margin:0;padding-left:24px;color:var(--text-secondary);line-height:1.7;}';
    echo '.card ul li{margin-bottom:8px;}';

    // Buttons
    echo '.actions{display:flex;gap:12px;flex-wrap:wrap;}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:12px;';
    echo 'background:var(--bg-panel);border:2px solid var(--border);color:var(--text);font-weight:600;';
    echo 'text-decoration:none;cursor:pointer;transition:all 0.2s ease;font-size:0.95rem;}';
    echo '.btn:hover{background:var(--bg-hover);border-color:var(--accent);box-shadow:var(--shadow);transform:translateY(-1px);text-decoration:none;}';
    echo '.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}';
    echo '.btn-primary:hover{background:var(--accent-hover);box-shadow:var(--shadow-red);transform:translateY(-2px);}';
    echo '.btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;}';

    // Responsive - Tablet
    echo '@media (max-width:1024px){';
    echo '.main{padding:32px;}';
    echo '.sidebar{width:250px;}';
    echo '}';

    // Hamburger Menu Button (hidden on desktop)
    echo '.hamburger-btn{display:none;position:fixed;top:16px;left:16px;z-index:1001;background:var(--accent);';
    echo 'color:white;border:none;width:48px;height:48px;border-radius:12px;cursor:pointer;';
    echo 'box-shadow:0 4px 12px rgba(220,38,38,0.4);transition:all 0.3s ease;align-items:center;justify-content:center;}';
    echo '.hamburger-btn:hover{background:var(--accent-hover);box-shadow:0 6px 16px rgba(220,38,38,0.6);transform:scale(1.05);}';
    echo '.hamburger-btn:active{transform:scale(0.95);}';
    echo '.hamburger-icon{display:flex;flex-direction:column;gap:4px;width:20px;}';
    echo '.hamburger-icon span{display:block;width:100%;height:2.5px;background:white;border-radius:2px;transition:all 0.3s ease;}';
    echo '.hamburger-btn.active .hamburger-icon span:nth-child(1){transform:rotate(45deg) translate(6px, 6px);}';
    echo '.hamburger-btn.active .hamburger-icon span:nth-child(2){opacity:0;}';
    echo '.hamburger-btn.active .hamburger-icon span:nth-child(3){transform:rotate(-45deg) translate(6px, -6px);}';

    // Mobile Overlay (backdrop)
    echo '.sidebar-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);';
    echo 'z-index:999;opacity:0;transition:opacity 0.3s ease;}';
    echo '.sidebar-overlay.active{display:block;opacity:1;}';

    // Responsive - Mobile (Tablet & Phone)
    echo '@media (max-width:900px){';
    // Show hamburger button
    echo '.hamburger-btn{display:flex;}';

    // Transform sidebar into slide-out drawer
    echo '.sidebar{position:fixed;top:0;left:-280px;bottom:0;width:280px;z-index:1000;';
    echo 'transition:left 0.3s ease;overflow-y:auto;padding:0;';
    echo 'box-shadow:0 0 0 rgba(0,0,0,0);border-right:none;}';
    echo '.sidebar.active{left:0;box-shadow:8px 0 24px rgba(0,0,0,0.3);}';

    // Main content shifts when sidebar opens
    echo '.main{margin-left:0;padding:80px 20px 20px 20px;transition:transform 0.3s ease;}';
    echo '.main.sidebar-open{transform:translateX(280px);}';

    // Mobile sidebar adjustments
    echo '.sidebar-user{padding:80px 20px 16px 20px;}'; // Extra top padding for hamburger
    echo '.brand{font-size:1.5rem;padding:16px 24px;}';
    echo '.brand small{display:block;font-size:0.65rem;}';
    echo '.nav-links{flex-direction:column;gap:6px;padding:0 16px;}';
    echo '.nav-links a{padding:14px 18px;font-size:0.95rem;justify-content:flex-start;}';
    echo '.nav-links a .icon{display:inline;}';

    // Page adjustments
    echo '.page-header h1{font-size:1.75rem;}';
    echo '.actions{flex-direction:column;width:100%;}';
    echo '.actions .btn{width:100%;justify-content:center;}';
    echo '}';

    // Responsive - Small Mobile
    echo '@media (max-width:640px){';
    echo '.main{padding:70px 16px 16px 16px;}';
    echo '.page-header{flex-direction:column;gap:12px;align-items:flex-start;}';
    echo '.page-header h1{font-size:1.5rem;line-height:1.2;}';
    echo '.page-header p{font-size:0.9rem;}';
    echo '.card{padding:20px;border-radius:12px;}';
    echo '.card h2{font-size:1.25rem;}';
    echo '.card h3{font-size:1.1rem;}';
    echo '.btn{font-size:0.9rem;padding:10px 18px;}';
    echo 'table{font-size:0.85rem;}';
    echo 'table th, table td{padding:10px 8px;}';
    echo '}';

    // Responsive - Extra Small Mobile
    echo '@media (max-width:480px){';
    echo '.hamburger-btn{width:44px;height:44px;top:12px;left:12px;}';
    echo '.sidebar{width:260px;left:-270px;padding:0;}';
    echo '.sidebar.active{left:0;}';
    echo '.sidebar-user{padding:70px 20px 16px 20px;}'; // Extra top padding for smaller hamburger
    echo '.main.sidebar-open{transform:translateX(260px);}';
    echo '.main{padding:64px 12px 12px 12px;}';
    echo '.page-header h1{font-size:1.3rem;}';
    echo '.card{padding:16px;}';
    echo '.btn{padding:9px 16px;font-size:0.85rem;}';
    echo 'table{font-size:0.8rem;}';
    echo 'table th, table td{padding:8px 6px;}';
    echo '}';
    echo '</style>';
    echo '</head><body class="theme-light">';

    // Add inline script to ensure hamburger menu works immediately
    echo '<script>';
    echo 'console.log("Page loaded, checking for hamburger elements...");';
    echo 'window.addEventListener("load", function() {';
    echo 'console.log("Window loaded");';
    echo 'const btn = document.getElementById("hamburger-btn");';
    echo 'const sidebar = document.getElementById("sidebar");';
    echo 'const overlay = document.getElementById("sidebar-overlay");';
    echo 'console.log("Elements found:", {btn: !!btn, sidebar: !!sidebar, overlay: !!overlay});';
    echo 'if(btn && sidebar && overlay) {';
    echo 'btn.onclick = function(e) {';
    echo 'e.preventDefault();';
    echo 'console.log("Button clicked!");';
    echo 'const isOpen = sidebar.classList.contains("active");';
    echo 'if(!isOpen) {';
    echo 'sidebar.classList.add("active");';
    echo 'overlay.classList.add("active");';
    echo 'btn.classList.add("active");';
    echo 'document.body.style.overflow = "hidden";';
    echo 'console.log("Menu opened");';
    echo '} else {';
    echo 'sidebar.classList.remove("active");';
    echo 'overlay.classList.remove("active");';
    echo 'btn.classList.remove("active");';
    echo 'document.body.style.overflow = "";';
    echo 'console.log("Menu closed");';
    echo '}';
    echo '};';
    echo 'overlay.onclick = function() {';
    echo 'sidebar.classList.remove("active");';
    echo 'overlay.classList.remove("active");';
    echo 'btn.classList.remove("active");';
    echo 'document.body.style.overflow = "";';
    echo '};';
    echo '}';
    echo '});';
    echo '</script>';
    echo '<script src="/js/customer-portal.js?v=5"></script>';

    // Hamburger Menu Button
    echo '<button class="hamburger-btn" id="hamburger-btn" aria-label="Toggle Menu">';
    echo '<div class="hamburger-icon">';
    echo '<span></span><span></span><span></span>';
    echo '</div>';
    echo '</button>';

    // Sidebar Overlay (backdrop)
    echo '<div class="sidebar-overlay" id="sidebar-overlay"></div>';

    echo '<div class="layout"><aside class="sidebar" id="sidebar">';

    // Customer name at top
    echo '<div class="sidebar-user">';
    if ($displayName !== null && $displayName !== '') {
        echo '<div class="user-name">', htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'), '</div>';
    } else {
        echo '<div class="user-name">Customer</div>';
    }
    if ($shopType) {
        echo '<div class="user-type">', htmlspecialchars($shopType, ENT_QUOTES, 'UTF-8'), '</div>';
    }
    echo '</div>';

    echo '<div class="brand">Salameh Tools<small>CUSTOMER PORTAL</small></div><nav class="nav-links">';

    foreach ($navItems as $slug => $item) {
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
        $icon = $item['icon'] ?? '';
        $classes = 'nav-link' . ($slug === $active ? ' active' : '');
        echo '<a class="', $classes, '" href="', $href, '"><span class="icon">', $icon, '</span> ', $label, '</a>';
    }

    echo '</nav>';

    // Simple logout link at bottom
    echo '<a href="logout.php" class="logout-link" onclick="return confirm(\'Are you sure you want to logout?\');">Logout</a>';

    echo '</aside><main class="main" id="main-content">';
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
 * Renders the end of a customer portal page layout.
 */
function customer_portal_render_layout_end(): void
{
    echo '</main></div></body></html>';
}
