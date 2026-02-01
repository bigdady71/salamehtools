<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$title = 'Sales Reps Stocks - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Sales Reps Van Stocks',
    'subtitle' => 'Manage inventory loaded on sales rep vans',
    'user' => $user,
    'active' => 'sales_reps_stocks',
]);

// Get all sales reps with their stock summary (using s_stock table)
$salesReps = $pdo->query("
    SELECT
        u.id,
        u.name,
        u.phone,
        COUNT(ss.id) as product_count,
        COALESCE(SUM(ss.qty_on_hand), 0) as total_quantity
    FROM users u
    LEFT JOIN s_stock ss ON ss.salesperson_id = u.id AND ss.qty_on_hand > 0
    WHERE u.role = 'sales_rep'
    GROUP BY u.id, u.name, u.phone
    ORDER BY u.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
    <?php foreach ($salesReps as $rep): ?>
        <?php
        $productCount = (int)$rep['product_count'];
        $totalQty = (float)($rep['total_quantity'] ?? 0);
        ?>
        <div class="card">
            <div style="margin-bottom:20px;">
                <h2 style="margin:0 0 8px;">
                    <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <?php if ($rep['phone']): ?>
                    <div style="font-size:0.9rem;color:var(--text-light);">
                        📞 <?= htmlspecialchars($rep['phone'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;justify-content:space-between;padding:16px;background:var(--bg);border-radius:8px;margin-bottom:16px;">
                <div>
                    <div style="font-size:1.5rem;font-weight:700;color:var(--primary);">
                        <?= $productCount ?>
                    </div>
                    <div style="font-size:0.85rem;color:var(--muted);">Products</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:1.5rem;font-weight:700;color:var(--success);">
                        <?= number_format($totalQty, 0) ?>
                    </div>
                    <div style="font-size:0.85rem;color:var(--muted);">Total Units</div>
                </div>
            </div>

            <?php if ($productCount > 0): ?>
                <?php
                // Get this rep's van stock (using s_stock table)
                $vanStockStmt = $pdo->prepare("
                    SELECT
                        ss.id,
                        ss.qty_on_hand as quantity,
                        ss.updated_at as loaded_at,
                        p.sku,
                        p.item_name,
                        p.unit,
                        p.image_url
                    FROM s_stock ss
                    INNER JOIN products p ON p.id = ss.product_id
                    WHERE ss.salesperson_id = ? AND ss.qty_on_hand > 0
                    ORDER BY p.item_name ASC
                    LIMIT 5
                ");
                $vanStockStmt->execute([$rep['id']]);
                $vanStock = $vanStockStmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div style="font-size:0.9rem;font-weight:600;margin-bottom:12px;color:var(--text);">
                    Recent Items:
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
                    <?php foreach ($vanStock as $item): ?>
                        <div style="font-size:0.85rem;padding:8px;background:white;border:1px solid var(--border);border-radius:6px;display:flex;gap:8px;align-items:center;">
                            <!-- Product Image -->
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                    alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;">
                            <?php else: ?>
                                <div style="width:40px;height:40px;background:#f3f4f6;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.2rem;">
                                    📦
                                </div>
                            <?php endif; ?>

                            <div style="flex:1;">
                                <div style="font-weight:600;">
                                    <?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="color:var(--text-light);margin-top:2px;font-size:0.8rem;">
                                    <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>

                            <div style="font-weight:700;color:var(--primary);white-space:nowrap;">
                                <?= number_format((float)$item['quantity'], 0) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:20px;color:var(--muted);font-size:0.9rem;">
                    No stock loaded
                </div>
            <?php endif; ?>

            <div style="display:flex;gap:8px;">
                <a href="load_van.php?rep_id=<?= $rep['id'] ?>" class="btn btn-success" style="flex:1;text-align:center;">
                    Load Van
                </a>
                <?php if ($productCount > 0): ?>
                    <a href="view_van_stock.php?rep_id=<?= $rep['id'] ?>" class="btn btn-secondary" style="flex:1;text-align:center;">
                        View All
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (empty($salesReps)): ?>
    <div class="card">
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            No sales representatives found
        </p>
    </div>
<?php endif; ?>

<?php
warehouse_portal_render_layout_end();
