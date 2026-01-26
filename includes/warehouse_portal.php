<?php

declare(strict_types=1);

/**
 * Warehouse Portal Layout and Authentication Functions
 * Blue-themed portal for warehouse operations
 */

/**
 * Ensure the current session belongs to a warehouse user and return the user data.
 *
 * @return array<string, mixed>
 */
function warehouse_portal_bootstrap(): array
{
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check if user is logged in and has warehouse role
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role'])) {
        header('Location: /salamehtools/pages/login.php');
        exit;
    }

    $userRole = $_SESSION['user']['role'];
    if ($userRole !== 'warehouse' && $userRole !== 'admin') {
        // Not a warehouse user - redirect to appropriate dashboard
        header('Location: /salamehtools/pages/login.php');
        exit;
    }

    // Get user ID from session
    $userId = (int)$_SESSION['user']['id'];

    // Get database connection
    $pdo = db();

    // Fetch user data
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            email,
            phone,
            role
        FROM users
        WHERE id = ? AND role IN ('warehouse', 'admin')
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // User not found or wrong role - logout
        session_destroy();
        header('Location: /salamehtools/pages/login.php?error=unauthorized');
        exit;
    }

    return $user;
}

/**
 * Navigation links available to warehouse users.
 *
 * @return array<string, array{label:string,href:string,icon:string}>
 */
function warehouse_portal_nav_links(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'href' => 'dashboard.php',
            'icon' => '📊',
        ],
        'products' => [
            'label' => 'Products',
            'href' => 'products.php',
            'icon' => '📦',
        ],
        'orders' => [
            'label' => 'Orders to Prepare',
            'href' => 'orders.php',
            'icon' => '📋',
        ],
        'sales_reps_stocks' => [
            'label' => 'Sales Reps Stocks',
            'href' => 'sales_reps_stocks.php',
            'icon' => '🚚',
        ],
        'stock_movements' => [
            'label' => 'Stock Movements',
            'href' => 'stock_movements.php',
            'icon' => '🔄',
        ],
        'stock_returns' => [
            'label' => 'Stock Returns',
            'href' => 'stock_returns.php',
            'icon' => '↩️',
        ],
        'van_restock' => [
            'label' => 'Van Restock Requests',
            'href' => 'van_restock_requests.php',
            'icon' => '🚚',
        ],
        'low_stock' => [
            'label' => 'Low Stock Alerts',
            'href' => 'low_stock.php',
            'icon' => '⚠️',
        ],
        'receiving' => [
            'label' => 'Receiving',
            'href' => 'receiving.php',
            'icon' => '📥',
        ],
        'adjustments' => [
            'label' => 'Inventory Adjustments',
            'href' => 'adjustments.php',
            'icon' => '🔧',
        ],
        'history' => [
            'label' => 'History',
            'href' => 'history.php',
            'icon' => '📜',
        ],
        'locations' => [
            'label' => 'Locations',
            'href' => 'locations.php',
            'icon' => '📍',
        ],
    ];
}

/**
 * Renders the start of a warehouse portal page layout with a blue-themed sidebar.
 *
 * @param array<string, mixed> $options
 */
function warehouse_portal_render_layout_start(array $options = []): void
{
    $title = $options['title'] ?? 'Warehouse Portal - Salameh Tools';
    $heading = $options['heading'] ?? 'Warehouse Management';
    $subtitle = $options['subtitle'] ?? '';
    $user = $options['user'] ?? [];
    $active = $options['active'] ?? '';
    $actions = $options['actions'] ?? [];

    $userName = $user['name'] ?? 'Warehouse User';

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), '</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';

    // Blue theme CSS for warehouse
    echo '<style>';
    echo ':root{';
    echo '--primary:#2563eb;';  // Blue
    echo '--primary-dark:#1e40af;';
    echo '--primary-light:#3b82f6;';
    echo '--accent:#60a5fa;';
    echo '--bg:#f8fafc;';
    echo '--card-bg:#ffffff;';
    echo '--text:#1e293b;';
    echo '--text-light:#475569;';
    echo '--border:#e2e8f0;';
    echo '--success:#10b981;';
    echo '--warning:#f59e0b;';
    echo '--danger:#ef4444;';
    echo '--muted:#94a3b8;';
    echo '}';

    echo 'html{overflow-x:hidden;}';
    echo 'body{margin:0;font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';
    echo 'background:var(--bg);color:var(--text);display:flex;min-height:100vh;line-height:1.6;overflow-x:hidden;width:100%;max-width:100vw;}';

    echo '.layout{display:flex;width:100%;max-width:100vw;overflow-x:hidden;}';

    // Sidebar styles
    echo '.sidebar{width:280px;background:var(--primary);color:white;padding:0;box-shadow:2px 0 8px rgba(0,0,0,0.1);';
    echo 'display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:100;overscroll-behavior:contain;}';

    echo '.sidebar-header{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,0.1);}';
    echo '.sidebar-header h1{margin:0;font-size:1.5rem;font-weight:700;}';
    echo '.sidebar-header p{margin:4px 0 0;font-size:0.85rem;opacity:0.8;}';

    echo '.sidebar-user{padding:20px;background:rgba(0,0,0,0.2);border-bottom:1px solid rgba(255,255,255,0.1);}';
    echo '.user-name{font-weight:600;font-size:1rem;margin-bottom:4px;}';
    echo '.user-role{font-size:0.85rem;opacity:0.8;}';

    echo '.sidebar nav{flex:1;padding:8px 0;}';
    echo '.sidebar a{display:flex;align-items:center;padding:12px 20px;color:rgba(255,255,255,0.8);';
    echo 'text-decoration:none;transition:all 0.2s;border-left:3px solid transparent;}';
    echo '.sidebar a:hover{background:rgba(255,255,255,0.1);color:white;}';
    echo '.sidebar a.active{background:rgba(255,255,255,0.15);color:white;border-left-color:white;font-weight:500;}';
    echo '.sidebar a .icon{margin-right:12px;font-size:1.2rem;}';

    echo '.logout-link{display:block;padding:16px 20px;color:rgba(255,255,255,0.8);text-decoration:none;';
    echo 'text-align:center;border-top:1px solid rgba(255,255,255,0.1);transition:all 0.2s;}';
    echo '.logout-link:hover{background:rgba(255,255,255,0.1);color:white;}';

    echo '.main{flex:1;margin-left:280px;padding:32px;width:100%;max-width:100vw;overflow-x:hidden;}';

    echo '.page-header{margin-bottom:32px;}';
    echo '.page-header h1{margin:0 0 8px;font-size:2rem;font-weight:700;color:var(--text);}';
    echo '.page-header p{margin:0;color:var(--text-light);font-size:1rem;}';

    echo '.actions-bar{display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;}';

    echo '.card{background:var(--card-bg);border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;}';
    echo '.card h2{margin:0 0 16px;font-size:1.25rem;font-weight:600;color:var(--text);}';

    echo '.btn{display:inline-block;padding:10px 20px;background:var(--primary);color:white;';
    echo 'text-decoration:none;border-radius:8px;font-weight:500;border:2px solid var(--primary);';
    echo 'cursor:pointer;transition:all 0.2s;font-size:0.95rem;}';
    echo '.btn:hover{background:var(--primary-dark);border-color:var(--primary-dark);}';
    echo '.btn-secondary{background:white;color:var(--primary);border-color:var(--border);}';
    echo '.btn-secondary:hover{background:var(--bg);border-color:var(--primary);}';
    echo '.btn-success{background:var(--success);border-color:var(--success);}';
    echo '.btn-success:hover{opacity:0.9;}';
    echo '.btn-danger{background:var(--danger);border-color:var(--danger);}';
    echo '.btn-danger:hover{opacity:0.9;}';
    echo '.btn-sm{padding:6px 12px;font-size:0.85rem;}';

    echo 'table{width:100%;border-collapse:collapse;background:white;border-radius:8px;overflow:hidden;}';
    echo 'thead{background:var(--bg);}';
    echo 'th{padding:12px;text-align:left;font-weight:600;color:var(--text);border-bottom:2px solid var(--border);}';
    echo 'td{padding:12px;border-bottom:1px solid var(--border);}';
    echo 'tr:last-child td{border-bottom:none;}';
    echo 'tr:hover{background:var(--bg);}';

    echo '.badge{display:inline-block;padding:4px 12px;border-radius:12px;font-size:0.85rem;font-weight:500;}';
    echo '.badge-success{background:#d1fae5;color:#065f46;}';
    echo '.badge-warning{background:#fef3c7;color:#92400e;}';
    echo '.badge-danger{background:#fee2e2;color:#991b1b;}';
    echo '.badge-info{background:#dbeafe;color:#1e40af;}';

    echo '.text-center{text-align:center;}';
    echo '.text-right{text-align:right;}';

    // Mobile styles
    echo '@media(max-width:900px){';
    echo '.sidebar{position:fixed;top:0;left:-280px;bottom:0;width:280px;z-index:1000;';
    echo 'transition:left 0.3s ease;overflow-y:auto;padding:0;';
    echo 'box-shadow:0 0 0 rgba(0,0,0,0);border-right:none;}';
    echo '.sidebar.active{left:0;box-shadow:8px 0 24px rgba(0,0,0,0.3);}';
    echo '.main{margin-left:0;padding:16px;width:100%;}';
    echo '.hamburger-btn{display:block!important;}';
    echo '.sidebar-overlay{display:block;}';
    echo '}';

    // Hamburger button
    echo '.hamburger-btn{display:none;position:fixed;top:16px;left:16px;z-index:1001;';
    echo 'background:var(--primary);border:none;border-radius:8px;width:48px;height:48px;';
    echo 'cursor:pointer;padding:12px;box-shadow:0 2px 8px rgba(0,0,0,0.2);}';
    echo '.hamburger-icon{display:flex;flex-direction:column;gap:6px;}';
    echo '.hamburger-icon span{display:block;width:24px;height:3px;background:white;border-radius:2px;';
    echo 'transition:all 0.3s;}';
    echo '.hamburger-btn.active .hamburger-icon span:nth-child(1){transform:rotate(45deg) translate(8px,8px);}';
    echo '.hamburger-btn.active .hamburger-icon span:nth-child(2){opacity:0;}';
    echo '.hamburger-btn.active .hamburger-icon span:nth-child(3){transform:rotate(-45deg) translate(7px,-7px);}';

    echo '.sidebar-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;';
    echo 'background:rgba(0,0,0,0.5);z-index:999;opacity:0;transition:opacity 0.3s;}';
    echo '.sidebar-overlay.active{display:block;opacity:1;}';

    echo '@media(max-width:900px){.main{margin-left:0;padding-top:80px;}}';

    echo '</style>';
    echo '</head>';
    echo '<body>';

    // Hamburger Menu Button
    echo '<button class="hamburger-btn" id="hamburger-btn" aria-label="Toggle Menu">';
    echo '<div class="hamburger-icon">';
    echo '<span></span><span></span><span></span>';
    echo '</div>';
    echo '</button>';

    // Sidebar Overlay
    echo '<div class="sidebar-overlay" id="sidebar-overlay"></div>';

    echo '<div class="layout"><aside class="sidebar" id="sidebar">';

    // Sidebar header
    echo '<div class="sidebar-header">';
    echo '<h1>Salameh Tools</h1>';
    echo '<p>Warehouse Portal</p>';
    echo '</div>';

    // User info
    echo '<div class="sidebar-user">';
    echo '<div class="user-name">', htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'), '</div>';
    echo '<div class="user-role">Warehouse Manager</div>';
    echo '</div>';

    // Navigation
    echo '<nav>';
    $navLinks = warehouse_portal_nav_links();
    foreach ($navLinks as $key => $link) {
        $isActive = ($key === $active) ? 'active' : '';
        echo '<a href="', $link['href'], '" class="', $isActive, '">';
        echo '<span class="icon">', $link['icon'], '</span>';
        echo '<span>', htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8'), '</span>';
        echo '</a>';
    }
    echo '</nav>';

    // Logout link
    echo '<a href="logout.php" class="logout-link" onclick="return confirm(\'Are you sure you want to logout?\');">Logout</a>';

    echo '</aside>';

    echo '<main class="main" id="main-content">';

    // Page header
    echo '<div class="page-header">';
    echo '<h1>', htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'), '</h1>';
    if ($subtitle) {
        echo '<p>', htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'), '</p>';
    }

    // Action buttons
    if (!empty($actions)) {
        echo '<div class="actions-bar">';
        foreach ($actions as $action) {
            $btnClass = $action['class'] ?? 'btn';
            $label = htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8');

            if (isset($action['href'])) {
                $href = htmlspecialchars($action['href'], ENT_QUOTES, 'UTF-8');
                echo '<a href="', $href, '" class="', $btnClass, '">', $label, '</a>';
            } elseif (isset($action['onclick'])) {
                $onclick = htmlspecialchars($action['onclick'], ENT_QUOTES, 'UTF-8');
                echo '<button onclick="', $onclick, '" class="', $btnClass, '">', $label, '</button>';
            }
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Renders the end of the warehouse portal layout.
 */
function warehouse_portal_render_layout_end(): void
{
    echo '</main></div>';

    // JavaScript for hamburger menu - placed at end so elements exist
    echo '<script>';
    echo 'function toggleWarehouseSidebar() {';
    echo '  var btn = document.getElementById("hamburger-btn");';
    echo '  var sidebar = document.getElementById("sidebar");';
    echo '  var overlay = document.getElementById("sidebar-overlay");';
    echo '  if (!btn || !sidebar || !overlay) return;';
    echo '  var isOpen = sidebar.classList.contains("active");';
    echo '  if (!isOpen) {';
    echo '    sidebar.classList.add("active");';
    echo '    overlay.classList.add("active");';
    echo '    btn.classList.add("active");';
    echo '    document.body.style.overflow = "hidden";';
    echo '  } else {';
    echo '    sidebar.classList.remove("active");';
    echo '    overlay.classList.remove("active");';
    echo '    btn.classList.remove("active");';
    echo '    document.body.style.overflow = "";';
    echo '  }';
    echo '}';
    echo 'document.getElementById("hamburger-btn").onclick = toggleWarehouseSidebar;';
    echo 'document.getElementById("sidebar-overlay").onclick = toggleWarehouseSidebar;';
    echo '</script>';

    echo '</body></html>';
}
