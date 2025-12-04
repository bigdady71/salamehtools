<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/csv_export.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = trim($_GET['search'] ?? '');
    $movementType = $_GET['type'] ?? 'all';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    // Build WHERE clause (same as main query)
    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR sm.note LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($movementType !== 'all') {
        $where[] = "sm.reason = :type";
        $params[':type'] = $movementType;
    }

    if ($dateFrom !== '') {
        $where[] = "sm.created_at >= :date_from";
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $where[] = "sm.created_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get movements for export (no LIMIT)
    $exportStmt = $pdo->prepare("
        SELECT
            sm.created_at as 'Date/Time',
            p.sku as 'SKU',
            p.item_name as 'Product',
            sm.reason as 'Movement Type',
            sm.delta_qty as 'Quantity Change',
            p.unit as 'Unit',
            u.name as 'Performed By',
            sm.note as 'Notes'
        FROM s_stock_movements sm
        INNER JOIN products p ON p.id = sm.product_id
        LEFT JOIN users u ON u.id = sm.salesperson_id
        {$whereClause}
        ORDER BY sm.created_at DESC
    ");
    $exportStmt->execute($params);
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'stock_movements_' . date('Y-m-d_His');
    exportToCSV($exportData, $filename);
}

// Filters
$search = trim($_GET['search'] ?? '');
$movementType = $_GET['type'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR sm.note LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($movementType !== 'all') {
    $where[] = "sm.reason = :type";
    $params[':type'] = $movementType;
}

if ($dateFrom !== '') {
    $where[] = "sm.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = "sm.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Get movements
$stmt = $pdo->prepare("
    SELECT
        sm.id,
        sm.reason as movement_type,
        sm.delta_qty as quantity_delta,
        sm.note as notes,
        sm.created_at,
        p.sku,
        p.item_name,
        p.unit,
        p.image_url,
        u.name as performed_by
    FROM s_stock_movements sm
    INNER JOIN products p ON p.id = sm.product_id
    LEFT JOIN users u ON u.id = sm.salesperson_id
    {$whereClause}
    ORDER BY sm.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Stock Movements - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Stock Movements',
    'subtitle' => 'Track all inventory movements and changes',
    'user' => $user,
    'active' => 'stock_movements',
]);
?>

<!-- Filters -->
<div class="card">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;align-items:end;">
        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="SKU, product, or notes..."
                   style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Movement Type</label>
            <select name="type" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
                <option value="all" <?= $movementType === 'all' ? 'selected' : '' ?>>All Types</option>
                <option value="load" <?= $movementType === 'load' ? 'selected' : '' ?>>Load</option>
                <option value="sale" <?= $movementType === 'sale' ? 'selected' : '' ?>>Sale</option>
                <option value="return" <?= $movementType === 'return' ? 'selected' : '' ?>>Return</option>
                <option value="adjustment" <?= $movementType === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                <option value="transfer_in" <?= $movementType === 'transfer_in' ? 'selected' : '' ?>>Transfer In</option>
                <option value="transfer_out" <?= $movementType === 'transfer_out' ? 'selected' : '' ?>>Transfer Out</option>
            </select>
        </div>

        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"
                   style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"
                   style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn" style="flex:1;">Filter</button>
            <a href="stock_movements.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>

    <!-- Export Button -->
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;">
        <a href="?export=csv<?= $search ? '&search=' . urlencode($search) : '' ?><?= $movementType !== 'all' ? '&type=' . urlencode($movementType) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>"
           class="btn btn-secondary"
           style="background:#059669;color:white;border-color:#059669;">
            📥 Export to CSV
        </a>
    </div>
</div>

<!-- Movements List -->
<div class="card">
    <h2>Recent Movements (<?= count($movements) ?>)</h2>

    <?php if (empty($movements)): ?>
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            No stock movements found
        </p>
    <?php else: ?>
        <!-- Movements List - Simplified -->
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($movements as $movement): ?>
                <?php
                $qtyDelta = (float)$movement['quantity_delta'];
                $isIncrease = $qtyDelta > 0;

                $typeLabels = [
                    'load' => 'Load',
                    'sale' => 'Sale',
                    'return' => 'Return',
                    'adjustment' => 'Adjustment',
                    'transfer_in' => 'Transfer In',
                    'transfer_out' => 'Transfer Out',
                ];

                $typeColors = [
                    'load' => '#059669',
                    'sale' => '#3b82f6',
                    'return' => '#f59e0b',
                    'adjustment' => '#6366f1',
                    'transfer_in' => '#059669',
                    'transfer_out' => '#dc2626',
                ];

                $typeLabel = $typeLabels[$movement['movement_type']] ?? ucfirst($movement['movement_type']);
                $typeColor = $typeColors[$movement['movement_type']] ?? '#6b7280';
                ?>
                <div style="background:white;border:2px solid #e5e7eb;border-radius:8px;padding:12px;display:flex;gap:12px;align-items:center;">
                    <!-- Product Image -->
                    <?php if (!empty($movement['image_url'])): ?>
                        <img src="<?= htmlspecialchars($movement['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($movement['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                             style="width:50px;height:50px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                    <?php else: ?>
                        <div style="width:50px;height:50px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.3rem;">
                            📦
                        </div>
                    <?php endif; ?>

                    <!-- Product Info -->
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:0.95rem;">
                            <?= htmlspecialchars($movement['item_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="font-size:0.8rem;color:#6b7280;">
                            <span style="font-family:monospace;font-weight:600;">
                                <?= htmlspecialchars($movement['sku'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($movement['notes']): ?>
                                &nbsp;•&nbsp;<?= htmlspecialchars($movement['notes'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Movement Type Badge -->
                    <div style="padding:6px 12px;background:<?= $typeColor ?>;color:white;border-radius:6px;font-size:0.8rem;font-weight:600;white-space:nowrap;">
                        <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <!-- Quantity Change -->
                    <div style="text-align:center;min-width:80px;">
                        <div style="font-weight:700;font-size:1.2rem;color:<?= $isIncrease ? '#059669' : '#dc2626' ?>;">
                            <?= $isIncrease ? '+' : '' ?><?= number_format($qtyDelta, 0) ?>
                        </div>
                        <div style="font-size:0.7rem;color:#6b7280;">
                            <?= htmlspecialchars($movement['unit'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>

                    <!-- Date & User -->
                    <div style="text-align:right;min-width:120px;font-size:0.75rem;color:#6b7280;">
                        <div><?= date('M d, Y', strtotime($movement['created_at'])) ?></div>
                        <div><?= date('H:i', strtotime($movement['created_at'])) ?></div>
                        <div style="margin-top:2px;font-weight:600;color:#9ca3af;">
                            <?= $movement['performed_by'] ? htmlspecialchars($movement['performed_by'], ENT_QUOTES, 'UTF-8') : 'System' ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
warehouse_portal_render_layout_end();
