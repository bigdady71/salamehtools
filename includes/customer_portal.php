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
        header('Location: /pages/login.php');
        exit;
    }

    $userRole = $_SESSION['user']['role'];
    if ($userRole !== 'viewer' && $userRole !== '') {
        // Not a customer - redirect to appropriate dashboard
        header('Location: /pages/login.php');
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
        header('Location: /pages/login.php?error=no_customer_record');
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
        header('Location: /pages/login.php?error=account_disabled');
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
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>', $escTitle, '</title>';
    echo $extraHead;
    echo '<link rel="stylesheet" href="/css/app.css?v=2">';
    echo '<style>';
    // Enhanced Light Theme with better colors and shadows
    echo ':root{';
    echo '--bg:#f8faf9;'; // Softer light green background
    echo '--bg-panel:#ffffff;';
    echo '--bg-panel-alt:#f0f9ff;'; // Light blue tint for variety
    echo '--bg-hover:#f0fdf4;'; // Light green hover
    echo '--text:#1f2937;'; // Darker text for better readability
    echo '--text-secondary:#4b5563;';
    echo '--muted:#6b7280;';
    echo '--accent:#10b981;'; // Green primary
    echo '--accent-hover:#059669;';
    echo '--accent-light:#d1fae5;';
    echo '--border:#e5e7eb;'; // Neutral border
    echo '--border-light:#f3f4f6;';
    echo '--shadow-sm:0 1px 2px 0 rgba(0,0,0,0.05);';
    echo '--shadow:0 4px 6px -1px rgba(0,0,0,0.1);';
    echo '--shadow-lg:0 10px 15px -3px rgba(0,0,0,0.1);';
    echo '--customer-gradient:linear-gradient(135deg, #10b981 0%, #059669 100%);';
    echo '}';

    // Base styles
    echo '*{box-sizing:border-box;}';
    echo 'body{margin:0;font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';
    echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;line-height:1.6;}';
    echo 'a{color:var(--accent);text-decoration:none;transition:color 0.2s;}';
    echo 'a:hover{color:var(--accent-hover);text-decoration:none;}';

    // Layout
    echo '.layout{display:flex;flex:1;}';
    echo '.sidebar{width:270px;background:var(--customer-gradient);border-right:none;padding:32px 24px;display:flex;';
    echo 'flex-direction:column;gap:32px;box-shadow:2px 0 16px rgba(16,185,129,0.1);position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:100;}';

    // Brand
    echo '.brand{font-size:1.75rem;font-weight:800;letter-spacing:0.02em;color:#ffffff;text-shadow:0 2px 8px rgba(0,0,0,0.15);}';
    echo '.brand small{display:block;font-size:0.7rem;font-weight:500;opacity:0.95;margin-top:6px;letter-spacing:0.15em;text-transform:uppercase;}';

    // Navigation
    echo '.nav-links{display:flex;flex-direction:column;gap:4px;}';
    echo '.nav-links a{padding:12px 16px;border-radius:12px;font-size:0.925rem;color:rgba(255,255,255,0.9);';
    echo 'transition:all 0.2s ease;font-weight:500;display:flex;align-items:center;gap:10px;text-decoration:none;}';
    echo '.nav-links a:hover{background:rgba(255,255,255,0.2);color:#ffffff;transform:translateX(4px);text-decoration:none;}';
    echo '.nav-links a.active{background:#ffffff;color:var(--accent);font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.15);transform:translateX(2px);}';

    // User card
    echo '.user-card{margin-top:auto;padding:20px;border-radius:16px;border:1px solid rgba(255,255,255,0.25);';
    echo 'background:rgba(255,255,255,0.15);font-size:0.9rem;color:rgba(255,255,255,0.95);backdrop-filter:blur(12px);}';
    echo '.user-card strong{display:block;font-size:1.05rem;color:#ffffff;font-weight:700;margin-bottom:4px;}';
    echo '.user-card span{opacity:0.9;}';

    // Main content
    echo '.main{flex:1;padding:40px;display:flex;flex-direction:column;gap:28px;margin-left:270px;max-width:1600px;}';

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
    echo 'background:var(--bg-panel);border:1.5px solid var(--border);color:var(--text);font-weight:600;';
    echo 'text-decoration:none;cursor:pointer;transition:all 0.2s ease;font-size:0.95rem;}';
    echo '.btn:hover{background:var(--bg-hover);border-color:var(--accent-light);box-shadow:var(--shadow);transform:translateY(-1px);text-decoration:none;}';
    echo '.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}';
    echo '.btn-primary:hover{background:var(--accent-hover);box-shadow:0 4px 12px rgba(16,185,129,0.25);transform:translateY(-1px);}';
    echo '.btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;}';

    // Responsive
    echo '@media (max-width:1024px){.main{padding:32px;}}';
    echo '@media (max-width:900px){';
    echo '.layout{flex-direction:column;}';
    echo '.sidebar{width:100%;flex-direction:row;align-items:center;justify-content:space-between;padding:20px 24px;';
    echo 'border-right:none;border-bottom:1px solid rgba(255,255,255,0.25);position:static;overflow-y:visible;z-index:100;}';
    echo '.nav-links{flex-direction:row;flex-wrap:wrap;gap:8px;}';
    echo '.nav-links a{padding:10px 14px;font-size:0.875rem;}';
    echo '.user-card{margin-top:0;padding:16px;}';
    echo '.main{padding:24px;margin-left:0;}';
    echo '.page-header h1{font-size:1.875rem;}';
    echo '}';
    echo '@media (max-width:640px){';
    echo '.sidebar{flex-direction:column;align-items:flex-start;gap:20px;}';
    echo '.nav-links{width:100%;}';
    echo '.nav-links a{width:100%;justify-content:flex-start;}';
    echo '.main{padding:20px;}';
    echo '.page-header{flex-direction:column;gap:12px;}';
    echo '.page-header h1{font-size:1.75rem;}';
    echo '.card{padding:24px;border-radius:16px;}';
    echo '}';
    echo '</style>';
    echo '<script src="/js/customer-portal.js?v=1" defer></script>';
    echo '</head><body class="theme-light"><div class="layout"><aside class="sidebar">';
    echo '<div class="brand">Salameh Tools<small>CUSTOMER PORTAL</small></div><nav class="nav-links">';

    foreach ($navItems as $slug => $item) {
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
        $icon = $item['icon'] ?? '';
        $classes = 'nav-link' . ($slug === $active ? ' active' : '');
        echo '<a class="', $classes, '" href="', $href, '">', $icon, ' ', $label, '</a>';
    }

    echo '</nav>';
    echo '<div class="user-card">';

    if ($displayName !== null && $displayName !== '') {
        echo '<strong>', htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'), '</strong>';
    } else {
        echo '<strong>Customer</strong>';
    }

    if ($shopType) {
        echo '<span>', htmlspecialchars($shopType, ENT_QUOTES, 'UTF-8'), '</span>';
    } else {
        echo '<span>Logged in</span>';
    }

    // Logout button
    echo '<a href="logout.php" style="display: inline-block; margin-top: 12px; padding: 8px 12px; background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-radius: 8px; text-align: center; font-weight: 600; font-size: 0.85rem; border: 1px solid rgba(239, 68, 68, 0.3); text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background=\'rgba(239, 68, 68, 0.25)\'; this.style.color=\'#ffffff\';" onmouseout="this.style.background=\'rgba(239, 68, 68, 0.15)\'; this.style.color=\'#fca5a5\';">🚪 Logout</a>';

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
 * Renders the end of a customer portal page layout.
 */
function customer_portal_render_layout_end(): void
{
    echo '</main></div></body></html>';
}
