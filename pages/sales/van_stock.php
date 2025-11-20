<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adjust_stock') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        $deltaQty = (float)($_POST['delta_qty'] ?? 0);
        $reason = (string)($_POST['reason'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));

        $errors = [];

        if ($productId <= 0) {
            $errors[] = 'Invalid product selected.';
        }

        if ($deltaQty == 0) {
            $errors[] = 'Quantity change cannot be zero.';
        }

        if (!in_array($reason, ['load', 'return', 'adjustment', 'transfer_in', 'transfer_out'])) {
            $errors[] = 'Invalid reason selected.';
        }

        if ($errors) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to adjust stock. Please fix the errors below:',
                'list' => $errors,
                'dismissible' => true,
            ];
        } else {
            try {
                $pdo->beginTransaction();

                // Check if stock record exists
                $checkStmt = $pdo->prepare("
                    SELECT qty_on_hand FROM s_stock
                    WHERE salesperson_id = :rep_id AND product_id = :product_id
                ");
                $checkStmt->execute([':rep_id' => $repId, ':product_id' => $productId]);
                $currentStock = $checkStmt->fetch(PDO::FETCH_ASSOC);

                $newQty = $currentStock ? (float)$currentStock['qty_on_hand'] + $deltaQty : $deltaQty;

                if ($newQty < 0) {
                    $errors[] = 'Adjustment would result in negative stock. Current: ' . ($currentStock ? $currentStock['qty_on_hand'] : 0);
                    $pdo->rollBack();
                    $flashes[] = [
                        'type' => 'error',
                        'title' => 'Invalid Adjustment',
                        'message' => 'Cannot adjust stock below zero.',
                        'list' => $errors,
                        'dismissible' => true,
                    ];
                } else {
                    // Log movement
                    $movementStmt = $pdo->prepare("
                        INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                        VALUES (:rep_id, :product_id, :delta_qty, :reason, :note, NOW())
                    ");
                    $movementStmt->execute([
                        ':rep_id' => $repId,
                        ':product_id' => $productId,
                        ':delta_qty' => $deltaQty,
                        ':reason' => $reason,
                        ':note' => $note !== '' ? $note : null,
                    ]);

                    // Update or insert stock record
                    if ($currentStock) {
                        $updateStmt = $pdo->prepare("
                            UPDATE s_stock
                            SET qty_on_hand = qty_on_hand + :delta_qty, updated_at = NOW()
                            WHERE salesperson_id = :rep_id AND product_id = :product_id
                        ");
                        $updateStmt->execute([
                            ':delta_qty' => $deltaQty,
                            ':rep_id' => $repId,
                            ':product_id' => $productId,
                        ]);
                    } else {
                        // Insert new stock record - created_at will be set automatically
                        $insertStmt = $pdo->prepare("
                            INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand, created_at, updated_at)
                            VALUES (:rep_id, :product_id, :qty, NOW(), NOW())
                        ");
                        $insertStmt->execute([
                            ':rep_id' => $repId,
                            ':product_id' => $productId,
                            ':qty' => $deltaQty,
                        ]);
                    }

                    $pdo->commit();

                    $actionLabel = $deltaQty > 0 ? 'increased' : 'decreased';
                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Stock Updated',
                        'message' => "Van stock has been {$actionLabel} successfully.",
                        'dismissible' => true,
                    ];
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to adjust van stock: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to adjust stock. Please try again.',
                    'dismissible' => true,
                ];
            }
        }
    }
}

// Handle CSV export
if ($action === 'export') {
    $search = trim((string)($_GET['search'] ?? ''));
    $categoryFilter = (string)($_GET['category'] ?? '');
    $alertFilter = (string)($_GET['alert'] ?? '');
    $ageFilter = (string)($_GET['age'] ?? '');

    // Build WHERE clause
    $where = ['s.salesperson_id = :rep_id'];
    $params = [':rep_id' => $repId];

    if ($search !== '') {
        $where[] = '(p.item_name LIKE :search OR p.sku LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($categoryFilter !== '') {
        $where[] = 'p.topcat = :category';
        $params[':category'] = $categoryFilter;
    }

    if ($alertFilter === 'low') {
        $where[] = 's.qty_on_hand <= 5';
    } elseif ($alertFilter === 'out') {
        $where[] = 's.qty_on_hand = 0';
    }

    // Age filtering
    if ($ageFilter === '30plus') {
        $where[] = 'DATEDIFF(NOW(), s.created_at) > 30';
    } elseif ($ageFilter === '60plus') {
        $where[] = 'DATEDIFF(NOW(), s.created_at) > 60';
    } elseif ($ageFilter === '90plus') {
        $where[] = 'DATEDIFF(NOW(), s.created_at) > 90';
    }

    $whereClause = implode(' AND ', $where);

    // Export query
    $exportStmt = $pdo->prepare("
        SELECT
            p.sku,
            p.item_name,
            p.second_name,
            p.topcat as category,
            p.unit,
            p.sale_price_usd,
            (p.sale_price_usd * 9000) as sale_price_lbp,
            s.qty_on_hand,
            (s.qty_on_hand * p.sale_price_usd) as stock_value_usd,
            (s.qty_on_hand * p.sale_price_usd * 9000) as stock_value_lbp,
            s.created_at as date_added,
            DATEDIFF(NOW(), s.created_at) as days_in_stock,
            s.updated_at as last_updated
        FROM s_stock s
        INNER JOIN products p ON p.id = s.product_id
        WHERE {$whereClause}
        ORDER BY s.created_at DESC
    ");
    $exportStmt->execute($params);
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=my_van_stock_' . date('Y-m-d_His') . '.csv');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Headers
    fputcsv($output, [
        'SKU',
        'Product Name',
        'Second Name',
        'Category',
        'Unit',
        'Sale Price (USD)',
        'Sale Price (LBP)',
        'Qty on Hand',
        'Stock Value (USD)',
        'Stock Value (LBP)',
        'Date Added',
        'Days in Stock',
        'Last Updated'
    ]);

    // Data rows
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['sku'],
            $row['item_name'],
            $row['second_name'] ?? '',
            $row['category'] ?? '',
            $row['unit'] ?? '',
            number_format((float)$row['sale_price_usd'], 2),
            number_format((float)$row['sale_price_lbp'], 0),
            number_format((float)$row['qty_on_hand'], 1),
            number_format((float)$row['stock_value_usd'], 2),
            number_format((float)$row['stock_value_lbp'], 0),
            $row['date_added'] ? date('Y-m-d H:i:s', strtotime($row['date_added'])) : '',
            $row['days_in_stock'] ?? '0',
            $row['last_updated'] ? date('Y-m-d H:i:s', strtotime($row['last_updated'])) : ''
        ]);
    }

    fclose($output);
    exit;
}

// Get filter parameters
$search = trim((string)($_GET['search'] ?? ''));
$categoryFilter = (string)($_GET['category'] ?? '');
$alertFilter = (string)($_GET['alert'] ?? '');
$ageFilter = (string)($_GET['age'] ?? '');

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Statistics including age metrics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT s.product_id) as total_products,
        COALESCE(SUM(s.qty_on_hand), 0) as total_units,
        COALESCE(SUM(s.qty_on_hand * p.sale_price_usd), 0) as total_value_usd,
        COUNT(CASE WHEN s.qty_on_hand <= 5 THEN 1 END) as low_stock_items,
        COUNT(CASE WHEN DATEDIFF(NOW(), s.created_at) > 60 THEN 1 END) as old_stock_items,
        COALESCE(AVG(DATEDIFF(NOW(), s.created_at)), 0) as avg_days_in_stock
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id AND s.qty_on_hand > 0
");
$statsStmt->execute([':rep_id' => $repId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Build WHERE clause
$where = ['s.salesperson_id = :rep_id'];
$params = [':rep_id' => $repId];

if ($search !== '') {
    $where[] = '(p.item_name LIKE :search OR p.sku LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($categoryFilter !== '') {
    $where[] = 'p.topcat = :category';
    $params[':category'] = $categoryFilter;
}

if ($alertFilter === 'low') {
    $where[] = 's.qty_on_hand <= 5';
} elseif ($alertFilter === 'out') {
    $where[] = 's.qty_on_hand = 0';
}

// Age filtering
if ($ageFilter === '30plus') {
    $where[] = 'DATEDIFF(NOW(), s.created_at) > 30';
} elseif ($ageFilter === '60plus') {
    $where[] = 'DATEDIFF(NOW(), s.created_at) > 60';
} elseif ($ageFilter === '90plus') {
    $where[] = 'DATEDIFF(NOW(), s.created_at) > 90';
}

$whereClause = implode(' AND ', $where);

// Get total count for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE {$whereClause}
");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalItems / $perPage);

// Fetch van stock with age calculation
$stockStmt = $pdo->prepare("
    SELECT
        s.product_id,
        s.qty_on_hand,
        s.created_at,
        s.updated_at,
        DATEDIFF(NOW(), s.created_at) as days_in_stock,
        p.sku,
        p.item_name,
        p.topcat as category,
        p.sale_price_usd,
        (s.qty_on_hand * p.sale_price_usd) as value_usd
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE {$whereClause}
    ORDER BY s.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stockStmt->bindValue($key, $value);
}
$stockStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stockStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stockStmt->execute();
$stockItems = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique categories for filter
$categoriesStmt = $pdo->prepare("
    SELECT DISTINCT p.topcat
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id AND p.topcat IS NOT NULL AND p.topcat != ''
    ORDER BY p.topcat
");
$categoriesStmt->execute([':rep_id' => $repId]);
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get recent movements (last 10)
$movementsStmt = $pdo->prepare("
    SELECT
        m.delta_qty,
        m.reason,
        m.note,
        m.created_at,
        p.item_name,
        p.sku
    FROM s_stock_movements m
    INNER JOIN products p ON p.id = m.product_id
    WHERE m.salesperson_id = :rep_id
    ORDER BY m.created_at DESC
    LIMIT 10
");
$movementsStmt->execute([':rep_id' => $repId]);
$recentMovements = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active products for the adjustment modal
$productsStmt = $pdo->query("
    SELECT id, sku, item_name, topcat as category
    FROM products
    WHERE is_active = 1
    ORDER BY item_name
");
$allProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Van Stock',
    'heading' => 'Van Stock Inventory',
    'subtitle' => 'Manage your van inventory and track stock movements with aging insights',
    'active' => 'van_stock',
    'user' => $user,
    'extra_head' => '<style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
        }
        .stat-warning {
            color: #f59e0b;
        }
        .stat-danger {
            color: #dc2626;
        }
        .stat-meta {
            font-size: 0.75rem;
            color: var(--muted);
            margin-top: 6px;
        }
        .filter-bar {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 22px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 180px;
        }
        .filter-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
        }
        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg);
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .btn-filter,
        .btn-adjust {
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-filter {
            background: var(--accent);
            color: #fff;
        }
        .btn-filter:hover {
            opacity: 0.9;
        }
        .btn-adjust {
            background: #8b5cf6;
            color: #fff;
        }
        .btn-adjust:hover {
            opacity: 0.9;
        }
        .btn-clear {
            background: #6b7280;
            color: #fff;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        .stock-table {
            background: var(--bg-panel);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .stock-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .stock-table th {
            background: var(--bg-panel-alt);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }
        .stock-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .stock-table tr:last-child td {
            border-bottom: none;
        }
        .stock-table tr:hover {
            background: var(--bg-panel-alt);
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .age-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .age-fresh {
            background: #d1fae5;
            color: #065f46;
        }
        .age-moderate {
            background: #fef3c7;
            color: #92400e;
        }
        .age-old {
            background: #fed7aa;
            color: #9a3412;
        }
        .age-stale {
            background: #fee2e2;
            color: #991b1b;
        }
        .movements-panel {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid var(--border);
        }
        .movements-panel h3 {
            margin: 0 0 16px;
            font-size: 1.2rem;
        }
        .movement-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .movement-item:last-child {
            border-bottom: none;
        }
        .movement-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .movement-delta {
            font-weight: 700;
        }
        .movement-delta.positive {
            color: #059669;
        }
        .movement-delta.negative {
            color: #dc2626;
        }
        .movement-meta {
            font-size: 0.8rem;
            color: var(--muted);
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }
        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            text-decoration: none;
            background: var(--bg-panel);
            color: var(--text);
            font-weight: 600;
        }
        .pagination a:hover {
            background: var(--accent);
            color: #fff;
        }
        .pagination .current {
            background: var(--accent);
            color: #fff;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: var(--bg-panel);
            border-radius: 16px;
            padding: 32px;
            max-width: 600px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: var(--muted);
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .btn-submit {
            background: var(--accent);
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-submit:hover {
            opacity: 0.9;
        }
        .btn-cancel {
            background: #6b7280;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-cancel:hover {
            opacity: 0.9;
        }
        .flash-stack {
            margin-bottom: 24px;
        }
        .flash {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 12px;
            border: 1px solid;
        }
        .flash-success {
            background: #d1fae5;
            border-color: #065f46;
            color: #065f46;
        }
        .flash-error {
            background: #fee2e2;
            border-color: #991b1b;
            color: #991b1b;
        }
        .flash-title {
            font-weight: 700;
            margin-bottom: 6px;
        }
        .flash-list {
            margin: 8px 0 0 20px;
            padding: 0;
        }
        .info-banner {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.1) 0%, rgba(6, 182, 212, 0.1) 100%);
            border: 1px solid rgba(14, 165, 233, 0.3);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .info-banner-icon {
            font-size: 1.5rem;
        }
        .info-banner-text {
            flex: 1;
            font-size: 0.9rem;
            color: var(--text);
        }
        .info-banner-text strong {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
        }
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>',
]);

// Render flash messages
if ($flashes) {
    echo '<div class="flash-stack">';
    foreach ($flashes as $flash) {
        $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
        $title = isset($flash['title']) ? htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') : '';
        $message = isset($flash['message']) ? htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') : '';
        $list = $flash['list'] ?? [];

        echo '<div class="flash flash-', $type, '">';
        if ($title) {
            echo '<div class="flash-title">', $title, '</div>';
        }
        if ($message) {
            echo '<div>', $message, '</div>';
        }
        if ($list) {
            echo '<ul class="flash-list">';
            foreach ($list as $item) {
                echo '<li>', htmlspecialchars($item, ENT_QUOTES, 'UTF-8'), '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    echo '</div>';
}

// Info banner for aging feature
if ((int)$stats['old_stock_items'] > 0) {
    echo '<div class="info-banner">';
    echo '<div class="info-banner-icon">⏰</div>';
    echo '<div class="info-banner-text">';
    echo '<strong>Old Stock Alert</strong>';
    echo 'You have <strong>', (int)$stats['old_stock_items'], ' items</strong> that have been in your van for more than 60 days. Consider reviewing these items for potential sales or returns.';
    echo '</div>';
    echo '</div>';
}

// Statistics cards
echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-label">Total Products</div>';
echo '<div class="stat-value">', number_format((int)$stats['total_products']), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Total Units</div>';
echo '<div class="stat-value">', number_format((float)$stats['total_units'], 1), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Stock Value (USD)</div>';
echo '<div class="stat-value">$', number_format((float)$stats['total_value_usd'], 2), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Low Stock Items</div>';
echo '<div class="stat-value stat-warning">', number_format((int)$stats['low_stock_items']), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Items Over 60 Days</div>';
echo '<div class="stat-value stat-danger">', number_format((int)$stats['old_stock_items']), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Avg Days in Stock</div>';
echo '<div class="stat-value">', number_format((float)$stats['avg_days_in_stock'], 0), '</div>';
echo '<div class="stat-meta">Average age of all items</div>';
echo '</div>';
echo '</div>';

// Filter bar
echo '<div class="filter-bar">';
echo '<div class="filter-group">';
echo '<label>Search</label>';
echo '<input type="text" id="filter-search" placeholder="Product name or SKU..." value="', htmlspecialchars($search, ENT_QUOTES, 'UTF-8'), '">';
echo '</div>';
echo '<div class="filter-group">';
echo '<label>Category</label>';
echo '<select id="filter-category">';
echo '<option value="">All Categories</option>';
foreach ($categories as $cat) {
    $selected = $categoryFilter === $cat ? ' selected' : '';
    echo '<option value="', htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'), '"', $selected, '>', htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'), '</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="filter-group">';
echo '<label>Stock Alert</label>';
echo '<select id="filter-alert">';
echo '<option value="">All Items</option>';
echo '<option value="low"', $alertFilter === 'low' ? ' selected' : '', '>Low Stock (≤5)</option>';
echo '<option value="out"', $alertFilter === 'out' ? ' selected' : '', '>Out of Stock</option>';
echo '</select>';
echo '</div>';
echo '<div class="filter-group">';
echo '<label>Age Filter</label>';
echo '<select id="filter-age">';
echo '<option value="">All Ages</option>';
echo '<option value="30plus"', $ageFilter === '30plus' ? ' selected' : '', '>Over 30 Days</option>';
echo '<option value="60plus"', $ageFilter === '60plus' ? ' selected' : '', '>Over 60 Days</option>';
echo '<option value="90plus"', $ageFilter === '90plus' ? ' selected' : '', '>Over 90 Days</option>';
echo '</select>';
echo '</div>';
echo '<div class="filter-actions">';
echo '<button class="btn-filter" onclick="applyFilters()">Apply Filters</button>';
if ($search !== '' || $categoryFilter !== '' || $alertFilter !== '' || $ageFilter !== '') {
    echo '<button class="btn-clear btn-filter" onclick="clearFilters()">Clear</button>';
}
// Export button with current filters
$exportUrl = '?action=export';
if ($search !== '') {
    $exportUrl .= '&search=' . urlencode($search);
}
if ($categoryFilter !== '') {
    $exportUrl .= '&category=' . urlencode($categoryFilter);
}
if ($alertFilter !== '') {
    $exportUrl .= '&alert=' . urlencode($alertFilter);
}
if ($ageFilter !== '') {
    $exportUrl .= '&age=' . urlencode($ageFilter);
}
echo '<a href="', htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8'), '" class="btn-filter" style="text-decoration: none; display: inline-block;">📊 Export CSV</a>';
echo '<button class="btn-adjust" onclick="openAdjustModal()">⚡ Adjust Stock</button>';
echo '</div>';
echo '</div>';

// Content grid
echo '<div class="content-grid">';

// Stock table
echo '<div class="stock-table">';

if (!$stockItems) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">📦</div>';
    echo '<h3>No stock items found</h3>';
    echo '<p>Add stock adjustments to get started.</p>';
    echo '</div>';
} else {
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Product</th>';
    echo '<th>SKU</th>';
    echo '<th>Category</th>';
    echo '<th>Quantity</th>';
    echo '<th>Unit Price</th>';
    echo '<th>Total Value</th>';
    echo '<th>Age</th>';
    echo '<th>Status</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($stockItems as $item) {
        $productId = (int)$item['product_id'];
        $sku = htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8');
        $itemName = htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8');
        $category = $item['category'] ? htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') : '-';
        $qty = (float)$item['qty_on_hand'];
        $priceUSD = (float)$item['sale_price_usd'];
        $valueUSD = (float)$item['value_usd'];
        $daysInStock = (int)($item['days_in_stock'] ?? 0);
        $createdAt = $item['created_at'] ? date('M j, Y', strtotime($item['created_at'])) : '-';

        // Determine age badge
        $ageClass = 'age-fresh';
        $ageLabel = "{$daysInStock}d";
        if ($daysInStock > 90) {
            $ageClass = 'age-stale';
            $ageLabel = "{$daysInStock}d ⚠️";
        } elseif ($daysInStock > 60) {
            $ageClass = 'age-old';
            $ageLabel = "{$daysInStock}d ⚠";
        } elseif ($daysInStock > 30) {
            $ageClass = 'age-moderate';
        }

        echo '<tr>';
        echo '<td><strong>', $itemName, '</strong><br><small style="color:var(--muted);">Added: ', $createdAt, '</small></td>';
        echo '<td>', $sku, '</td>';
        echo '<td>', $category, '</td>';
        echo '<td><strong>', number_format($qty, 1), '</strong></td>';
        echo '<td>$', number_format($priceUSD, 2), '</td>';
        echo '<td>$', number_format($valueUSD, 2), '</td>';
        echo '<td><span class="age-badge ', $ageClass, '">', $ageLabel, '</span></td>';
        echo '<td>';
        if ($qty <= 0) {
            echo '<span class="badge badge-danger">Out of Stock</span>';
        } elseif ($qty <= 5) {
            echo '<span class="badge badge-warning">Low Stock</span>';
        } else {
            echo '<span class="badge badge-success">In Stock</span>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

echo '</div>';

// Recent movements panel
echo '<div class="movements-panel">';
echo '<h3>Recent Movements</h3>';

if (!$recentMovements) {
    echo '<p style="color:var(--muted);font-size:0.9rem;">No recent movements to display.</p>';
} else {
    foreach ($recentMovements as $movement) {
        $deltaQty = (float)$movement['delta_qty'];
        $reason = htmlspecialchars($movement['reason'], ENT_QUOTES, 'UTF-8');
        $note = $movement['note'] ? htmlspecialchars($movement['note'], ENT_QUOTES, 'UTF-8') : '';
        $itemName = htmlspecialchars($movement['item_name'], ENT_QUOTES, 'UTF-8');
        $createdAt = date('M j, g:i A', strtotime($movement['created_at']));

        $deltaClass = $deltaQty > 0 ? 'positive' : 'negative';
        $deltaSign = $deltaQty > 0 ? '+' : '';

        echo '<div class="movement-item">';
        echo '<div class="movement-header">';
        echo '<span>', $itemName, '</span>';
        echo '<span class="movement-delta ', $deltaClass, '">', $deltaSign, number_format($deltaQty, 1), '</span>';
        echo '</div>';
        echo '<div class="movement-meta">';
        echo '<strong>', ucfirst(str_replace('_', ' ', $reason)), '</strong>';
        if ($note) {
            echo ' • ', $note;
        }
        echo ' • ', $createdAt;
        echo '</div>';
        echo '</div>';
    }
}

echo '</div>';

echo '</div>'; // End content-grid

// Pagination
if ($totalPages > 1) {
    echo '<div class="pagination">';
    if ($page > 1) {
        $prevUrl = '?page=' . ($page - 1);
        if ($search) {
            $prevUrl .= '&search=' . urlencode($search);
        }
        if ($categoryFilter) {
            $prevUrl .= '&category=' . urlencode($categoryFilter);
        }
        if ($alertFilter) {
            $prevUrl .= '&alert=' . urlencode($alertFilter);
        }
        if ($ageFilter) {
            $prevUrl .= '&age=' . urlencode($ageFilter);
        }
        echo '<a href="', htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'), '">Previous</a>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    for ($i = $start; $i <= $end; $i++) {
        $pageUrl = '?page=' . $i;
        if ($search) {
            $pageUrl .= '&search=' . urlencode($search);
        }
        if ($categoryFilter) {
            $pageUrl .= '&category=' . urlencode($categoryFilter);
        }
        if ($alertFilter) {
            $pageUrl .= '&alert=' . urlencode($alertFilter);
        }
        if ($ageFilter) {
            $pageUrl .= '&age=' . urlencode($ageFilter);
        }

        if ($i === $page) {
            echo '<span class="current">', $i, '</span>';
        } else {
            echo '<a href="', htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'), '">', $i, '</a>';
        }
    }

    if ($page < $totalPages) {
        $nextUrl = '?page=' . ($page + 1);
        if ($search) {
            $nextUrl .= '&search=' . urlencode($search);
        }
        if ($categoryFilter) {
            $nextUrl .= '&category=' . urlencode($categoryFilter);
        }
        if ($alertFilter) {
            $nextUrl .= '&alert=' . urlencode($alertFilter);
        }
        if ($ageFilter) {
            $nextUrl .= '&age=' . urlencode($ageFilter);
        }
        echo '<a href="', htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'), '">Next</a>';
    }
    echo '</div>';
}

// Adjust Stock Modal
echo '<div id="adjustModal" class="modal">';
echo '<div class="modal-content">';
echo '<div class="modal-header">';
echo '<h2>Adjust Van Stock</h2>';
echo '<button class="modal-close" onclick="closeAdjustModal()">&times;</button>';
echo '</div>';
echo '<form method="POST">';
echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
echo '<input type="hidden" name="action" value="adjust_stock">';
echo '<div class="form-group">';
echo '<label>Product <span style="color:red;">*</span></label>';
echo '<select name="product_id" required>';
echo '<option value="">Select a product...</option>';
foreach ($allProducts as $product) {
    $prodId = (int)$product['id'];
    $prodSku = htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8');
    $prodName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
    $prodCat = $product['category'] ? ' [' . htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') . ']' : '';
    echo '<option value="', $prodId, '">', $prodSku, ' - ', $prodName, $prodCat, '</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Quantity Change <span style="color:red;">*</span></label>';
echo '<input type="number" name="delta_qty" step="0.1" required placeholder="Use negative for decrease">';
echo '<small style="color:var(--muted);font-size:0.85rem;">Positive numbers add stock, negative numbers remove stock</small>';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Reason <span style="color:red;">*</span></label>';
echo '<select name="reason" required>';
echo '<option value="">Select a reason...</option>';
echo '<option value="load">Load from Warehouse</option>';
echo '<option value="return">Return to Warehouse</option>';
echo '<option value="adjustment">Manual Adjustment</option>';
echo '<option value="transfer_in">Transfer In</option>';
echo '<option value="transfer_out">Transfer Out</option>';
echo '</select>';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Notes</label>';
echo '<textarea name="note" placeholder="Optional notes about this adjustment..."></textarea>';
echo '</div>';
echo '<div class="form-actions">';
echo '<button type="button" class="btn-cancel" onclick="closeAdjustModal()">Cancel</button>';
echo '<button type="submit" class="btn-submit">Apply Adjustment</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

echo '<script>';
echo 'function applyFilters() {';
echo '  const search = document.getElementById("filter-search").value;';
echo '  const category = document.getElementById("filter-category").value;';
echo '  const alert = document.getElementById("filter-alert").value;';
echo '  const age = document.getElementById("filter-age").value;';
echo '  let url = "?page=1";';
echo '  if (search) url += "&search=" + encodeURIComponent(search);';
echo '  if (category) url += "&category=" + encodeURIComponent(category);';
echo '  if (alert) url += "&alert=" + encodeURIComponent(alert);';
echo '  if (age) url += "&age=" + encodeURIComponent(age);';
echo '  window.location.href = url;';
echo '}';
echo 'function clearFilters() {';
echo '  window.location.href = "?";';
echo '}';
echo 'function openAdjustModal() {';
echo '  document.getElementById("adjustModal").classList.add("active");';
echo '}';
echo 'function closeAdjustModal() {';
echo '  document.getElementById("adjustModal").classList.remove("active");';
echo '}';
echo 'document.getElementById("filter-search").addEventListener("keypress", function(e) {';
echo '  if (e.key === "Enter") applyFilters();';
echo '});';
echo '</script>';

sales_portal_render_layout_end();
