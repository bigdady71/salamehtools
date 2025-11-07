<?php
/**
 * Sales Representatives Management Page
 *
 * Features:
 * - List all sales reps with performance metrics
 * - View sales rep statistics
 * - Activate/Deactivate sales reps
 * - View assigned customers
 * - Track performance (orders, revenue)
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Sales Representatives';

// ========================================
// HANDLE SALES REP STATUS TOGGLE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $salesRepId = (int)($_POST['sales_rep_id'] ?? 0);

    if ($salesRepId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT name, is_active FROM users WHERE id = ? AND role = 'sales_rep'");
            $stmt->execute([$salesRepId]);
            $salesRep = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($salesRep) {
                $newStatus = $salesRep['is_active'] ? 0 : 1;
                $updateStmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $updateStmt->execute([$newStatus, $salesRepId]);

                $statusText = $newStatus ? 'activated' : 'deactivated';
                flash('success', "Sales rep '{$salesRep['name']}' {$statusText} successfully.");
            }
        } catch (PDOException $e) {
            flash('error', 'Error updating sales rep status: ' . $e->getMessage());
        }
    }

    header('Location: sales_reps.php');
    exit;
}

// ========================================
// SEARCH AND FILTERS
// ========================================
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'total_revenue';
$sortOrder = $_GET['order'] ?? 'desc';

// Build WHERE clause
$whereConditions = ["u.role = 'sales_rep'"];
$params = [];

if ($search !== '') {
    $whereConditions[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($filterStatus === 'active') {
    $whereConditions[] = "u.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $whereConditions[] = "u.is_active = 0";
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Validate sort column - map to actual columns for ORDER BY
$sortMap = [
    'name' => 'u.name',
    'customer_count' => 'customer_count',
    'order_count' => 'order_count',
    'total_revenue' => 'total_revenue_usd'
];
$sortColumn = $sortMap[$sortBy] ?? 'total_revenue_usd';
$sortDir = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// ========================================
// FETCH SALES REPS WITH PERFORMANCE METRICS
// ========================================
$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.is_active,
        u.created_at,
        COUNT(DISTINCT c.id) as customer_count,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(i.total_usd), 0) as total_revenue_usd,
        COALESCE(SUM(i.total_lbp), 0) as total_revenue_lbp,
        MAX(o.created_at) as last_order_date
    FROM users u
    LEFT JOIN customers c ON c.assigned_sales_rep_id = u.id
    LEFT JOIN orders o ON o.sales_rep_id = u.id
    LEFT JOIN invoices i ON i.order_id = o.id AND i.status IN ('issued', 'paid')
    {$whereClause}
    GROUP BY u.id
    ORDER BY {$sortColumn} {$sortDir}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$salesReps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// CALCULATE OVERALL STATISTICS
// ========================================
$overallStats = $pdo->query("
    SELECT
        COUNT(DISTINCT u.id) as total_sales_reps,
        SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active_reps,
        SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) as inactive_reps,
        COUNT(DISTINCT c.id) as total_customers_assigned,
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(i.total_usd), 0) as total_revenue_usd
    FROM users u
    LEFT JOIN customers c ON c.assigned_sales_rep_id = u.id
    LEFT JOIN orders o ON o.sales_rep_id = u.id
    LEFT JOIN invoices i ON i.order_id = o.id AND i.status IN ('issued', 'paid')
    WHERE u.role = 'sales_rep'
")->fetch(PDO::FETCH_ASSOC);

// Calculate average performance per active rep
$activeRepsCount = (int)$overallStats['active_reps'];
$avgOrdersPerRep = $activeRepsCount > 0 ? $overallStats['total_orders'] / $activeRepsCount : 0;
$avgRevenuePerRep = $activeRepsCount > 0 ? $overallStats['total_revenue_usd'] / $activeRepsCount : 0;

$flashes = consume_flashes();

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Sales Representatives',
    'subtitle' => 'Monitor sales team performance and customer assignments.',
    'active' => 'sales_reps',
    'user' => $user,
]);

admin_render_flashes($flashes);
?>

<!-- Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Total Sales Reps</div>
        <div style="font-size: 2rem; font-weight: 700; color: #111827;"><?= number_format($overallStats['total_sales_reps']) ?></div>
        <div style="color: #10b981; font-size: 0.85rem; margin-top: 4px;">
            <?= $overallStats['active_reps'] ?> active
        </div>
    </div>

    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Total Customers</div>
        <div style="font-size: 2rem; font-weight: 700; color: #1f6feb;"><?= number_format($overallStats['total_customers_assigned']) ?></div>
        <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
            Assigned to reps
        </div>
    </div>

    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Total Orders</div>
        <div style="font-size: 2rem; font-weight: 700; color: #8b5cf6;"><?= number_format($overallStats['total_orders']) ?></div>
        <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
            Avg <?= number_format($avgOrdersPerRep, 1) ?> per rep
        </div>
    </div>

    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Total Revenue</div>
        <div style="font-size: 2rem; font-weight: 700; color: #10b981;">$<?= number_format($overallStats['total_revenue_usd'], 0) ?></div>
        <div style="color: #6b7280; font-size: 0.85rem; margin-top: 4px;">
            Avg $<?= number_format($avgRevenuePerRep, 0) ?> per rep
        </div>
    </div>
</div>

<!-- Search and Filters -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" action="sales_reps.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; align-items: end;">
        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Name, email, or phone..."
                   style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
        </div>

        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">Status</label>
            <select name="status" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
            </select>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="flex: 1;">Apply Filters</button>
            <a href="sales_reps.php" class="btn" style="text-align: center;">Clear</a>
        </div>
    </form>
</div>

<!-- Results Summary -->
<div style="margin-bottom: 15px; color: #6b7280; display: flex; justify-content: space-between; align-items: center;">
    <div>
        Showing <strong><?= count($salesReps) ?></strong> sales rep(s)
        <?php if ($search || $filterStatus !== 'all'): ?>
            <a href="sales_reps.php" style="margin-left: 10px; color: #ef4444;">Clear all filters</a>
        <?php endif; ?>
    </div>
    <div>
        Sort by:
        <a href="?search=<?= urlencode($search) ?>&status=<?= $filterStatus ?>&sort=total_revenue&order=desc"
           style="<?= $sortBy === 'total_revenue' ? 'font-weight: 700; color: #1f6feb;' : 'color: #6b7280;' ?>">
            Revenue
        </a> |
        <a href="?search=<?= urlencode($search) ?>&status=<?= $filterStatus ?>&sort=order_count&order=desc"
           style="<?= $sortBy === 'order_count' ? 'font-weight: 700; color: #1f6feb;' : 'color: #6b7280;' ?>">
            Orders
        </a> |
        <a href="?search=<?= urlencode($search) ?>&status=<?= $filterStatus ?>&sort=customer_count&order=desc"
           style="<?= $sortBy === 'customer_count' ? 'font-weight: 700; color: #1f6feb;' : 'color: #6b7280;' ?>">
            Customers
        </a>
    </div>
</div>

<!-- Sales Reps Table -->
<div class="card">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Sales Rep</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Customers</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Orders</th>
                    <th style="padding: 12px; text-align: right; font-weight: 600; color: #374151;">Revenue (USD)</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Last Order</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Status</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salesReps)): ?>
                <tr>
                    <td colspan="7" style="padding: 40px; text-align: center; color: #9ca3af;">
                        No sales representatives found.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($salesReps as $rep): ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px;">
                        <div style="font-weight: 600; color: #111827;"><?= htmlspecialchars($rep['name']) ?></div>
                        <div style="font-size: 0.85rem; color: #6b7280;">
                            <?= $rep['email'] ? htmlspecialchars($rep['email']) : 'No email' ?>
                        </div>
                        <?php if ($rep['phone']): ?>
                        <div style="font-size: 0.85rem; color: #6b7280;">
                            <?= htmlspecialchars($rep['phone']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <div style="font-weight: 600; font-size: 1.2rem; color: #1f6feb;">
                            <?= number_format($rep['customer_count']) ?>
                        </div>
                        <a href="customers.php?sales_rep=<?= $rep['id'] ?>"
                           style="font-size: 0.85rem; color: #6b7280;">
                            View customers
                        </a>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <div style="font-weight: 600; font-size: 1.2rem; color: #8b5cf6;">
                            <?= number_format($rep['order_count']) ?>
                        </div>
                        <a href="orders.php?sales_rep=<?= $rep['id'] ?>"
                           style="font-size: 0.85rem; color: #6b7280;">
                            View orders
                        </a>
                    </td>
                    <td style="padding: 12px; text-align: right;">
                        <div style="font-weight: 700; font-size: 1.1rem; color: #10b981;">
                            $<?= number_format($rep['total_revenue_usd'], 2) ?>
                        </div>
                        <?php if ($rep['order_count'] > 0): ?>
                        <div style="font-size: 0.85rem; color: #6b7280;">
                            Avg: $<?= number_format($rep['total_revenue_usd'] / $rep['order_count'], 2) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; color: #374151;">
                        <?php if ($rep['last_order_date']): ?>
                            <?= date('M d, Y', strtotime($rep['last_order_date'])) ?>
                            <div style="font-size: 0.85rem; color: #6b7280;">
                                <?= date('H:i', strtotime($rep['last_order_date'])) ?>
                            </div>
                        <?php else: ?>
                            <span style="color: #9ca3af;">No orders yet</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <?php if ($rep['is_active']): ?>
                        <span style="display: inline-block; padding: 4px 12px; background: #d1fae5; color: #065f46; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                            Active
                        </span>
                        <?php else: ?>
                        <span style="display: inline-block; padding: 4px 12px; background: #fee2e2; color: #991b1b; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                            Inactive
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="sales_rep_id" value="<?= $rep['id'] ?>">
                            <button type="submit"
                                    class="btn"
                                    style="padding: 6px 12px; font-size: 0.85rem; background: <?= $rep['is_active'] ? '#fef3c7' : '#d1fae5' ?>;"
                                    onclick="return confirm('Are you sure you want to <?= $rep['is_active'] ? 'deactivate' : 'activate' ?> this sales rep?')">
                                <?= $rep['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Performance Insights -->
<?php if (!empty($salesReps)): ?>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">
    <!-- Top Performer -->
    <?php
    $topPerformer = array_reduce($salesReps, function($carry, $item) {
        return ($item['total_revenue_usd'] > ($carry['total_revenue_usd'] ?? 0)) ? $item : $carry;
    }, []);
    ?>
    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Top Performer</div>
        <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 4px;"><?= htmlspecialchars($topPerformer['name'] ?? 'N/A') ?></div>
        <div style="font-size: 1.2rem; opacity: 0.9;">$<?= number_format($topPerformer['total_revenue_usd'] ?? 0, 0) ?> revenue</div>
    </div>

    <!-- Most Customers -->
    <?php
    $mostCustomers = array_reduce($salesReps, function($carry, $item) {
        return ($item['customer_count'] > ($carry['customer_count'] ?? 0)) ? $item : $carry;
    }, []);
    ?>
    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #1f6feb 0%, #0c4ab3 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Most Customers</div>
        <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 4px;"><?= htmlspecialchars($mostCustomers['name'] ?? 'N/A') ?></div>
        <div style="font-size: 1.2rem; opacity: 0.9;"><?= number_format($mostCustomers['customer_count'] ?? 0) ?> customers</div>
    </div>

    <!-- Most Orders -->
    <?php
    $mostOrders = array_reduce($salesReps, function($carry, $item) {
        return ($item['order_count'] > ($carry['order_count'] ?? 0)) ? $item : $carry;
    }, []);
    ?>
    <div class="card" style="padding: 20px; background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white;">
        <div style="font-size: 0.9rem; margin-bottom: 8px; opacity: 0.9;">Most Orders</div>
        <div style="font-size: 1.5rem; font-weight: 700; margin-bottom: 4px;"><?= htmlspecialchars($mostOrders['name'] ?? 'N/A') ?></div>
        <div style="font-size: 1.2rem; opacity: 0.9;"><?= number_format($mostOrders['order_count'] ?? 0) ?> orders</div>
    </div>
</div>
<?php endif; ?>

<!-- Help Text -->
<div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; color: #6b7280; font-size: 0.9rem;">
    <strong>Tips:</strong>
    <ul style="margin: 8px 0 0 20px;">
        <li>Click "View customers" or "View orders" to see rep-specific data</li>
        <li>Sort by different metrics to identify top and low performers</li>
        <li>Deactivate reps to prevent new orders without losing historical data</li>
        <li>Performance cards highlight your best sales reps</li>
    </ul>
</div>

<?php
admin_render_layout_end();
?>
