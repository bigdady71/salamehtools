<?php
/**
 * Customers Management Page
 *
 * Features:
 * - List all customers with details
 * - Search and filter customers
 * - View customer statistics
 * - Export customer list
 * - Assign sales reps
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
$title = 'Admin · Customers';

// ========================================
// HANDLE CUSTOMER STATUS TOGGLE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $customerId = (int)($_POST['customer_id'] ?? 0);

    if ($customerId > 0) {
        try {
            // Get current status
            $stmt = $pdo->prepare("SELECT is_active FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customer) {
                $newStatus = $customer['is_active'] ? 0 : 1;
                $updateStmt = $pdo->prepare("UPDATE customers SET is_active = ? WHERE id = ?");
                $updateStmt->execute([$newStatus, $customerId]);

                $statusText = $newStatus ? 'activated' : 'deactivated';
                flash('success', "Customer {$statusText} successfully.");
            }
        } catch (PDOException $e) {
            flash('error', 'Error updating customer status: ' . $e->getMessage());
        }
    }

    header('Location: customers.php');
    exit;
}

// ========================================
// SEARCH AND FILTERS
// ========================================
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? 'all';
$filterSalesRep = (int)($_GET['sales_rep'] ?? 0);
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'asc';

// Build WHERE clause
$whereConditions = [];
$params = [];

if ($search !== '') {
    $whereConditions[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.location LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($filterStatus === 'active') {
    $whereConditions[] = "c.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $whereConditions[] = "c.is_active = 0";
}

if ($filterSalesRep > 0) {
    $whereConditions[] = "c.assigned_sales_rep_id = ?";
    $params[] = $filterSalesRep;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Validate sort column
$allowedSort = ['name', 'phone', 'location', 'created_at', 'order_count', 'total_revenue'];
$sortColumn = in_array($sortBy, $allowedSort) ? $sortBy : 'name';
$sortDir = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

// ========================================
// FETCH CUSTOMERS WITH STATISTICS
// ========================================
$sql = "
    SELECT
        c.*,
        u.name as sales_rep_name,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(i.total_usd), 0) as total_revenue_usd,
        MAX(o.created_at) as last_order_date
    FROM customers c
    LEFT JOIN users u ON u.id = c.assigned_sales_rep_id
    LEFT JOIN orders o ON o.customer_id = c.id
    LEFT JOIN invoices i ON i.order_id = o.id
    {$whereClause}
    GROUP BY c.id
    ORDER BY c.{$sortColumn} {$sortDir}
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// FETCH SALES REPS FOR FILTER
// ========================================
$salesRepsStmt = $pdo->query("
    SELECT id, name
    FROM users
    WHERE role = 'sales_rep' AND is_active = 1
    ORDER BY name ASC
");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// CALCULATE STATISTICS
// ========================================
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) as total_customers,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_customers,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_customers,
        SUM(CASE WHEN assigned_sales_rep_id IS NULL THEN 1 ELSE 0 END) as unassigned_customers
    FROM customers
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$flashes = consume_flashes();

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Customers',
    'subtitle' => 'Manage your customer database and track their activity.',
    'active' => 'customers',
    'user' => $user,
]);

admin_render_flashes($flashes);
?>

<!-- Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Total Customers</div>
        <div style="font-size: 2rem; font-weight: 700; color: #111827;"><?= number_format($stats['total_customers']) ?></div>
    </div>

    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Active</div>
        <div style="font-size: 2rem; font-weight: 700; color: #10b981;"><?= number_format($stats['active_customers']) ?></div>
    </div>

    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Inactive</div>
        <div style="font-size: 2rem; font-weight: 700; color: #ef4444;"><?= number_format($stats['inactive_customers']) ?></div>
    </div>

    <div class="card" style="padding: 20px;">
        <div style="color: #6b7280; font-size: 0.9rem; margin-bottom: 8px;">Unassigned</div>
        <div style="font-size: 2rem; font-weight: 700; color: #f59e0b;"><?= number_format($stats['unassigned_customers']) ?></div>
    </div>
</div>

<!-- Search and Filters -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" action="customers.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Name, phone, or location..."
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

        <div>
            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">Sales Rep</label>
            <select name="sales_rep" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                <option value="0">All Sales Reps</option>
                <?php foreach ($salesReps as $rep): ?>
                <option value="<?= $rep['id'] ?>" <?= $filterSalesRep == $rep['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rep['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="flex: 1;">Apply Filters</button>
            <a href="customers.php" class="btn" style="text-align: center;">Clear</a>
        </div>
    </form>
</div>

<!-- Results Summary -->
<div style="margin-bottom: 15px; color: #6b7280;">
    Showing <strong><?= count($customers) ?></strong> customer(s)
    <?php if ($search || $filterStatus !== 'all' || $filterSalesRep > 0): ?>
        <a href="customers.php" style="margin-left: 10px; color: #ef4444;">Clear all filters</a>
    <?php endif; ?>
</div>

<!-- Customers Table -->
<div class="card">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">
                        <a href="?search=<?= urlencode($search) ?>&status=<?= $filterStatus ?>&sales_rep=<?= $filterSalesRep ?>&sort=name&order=<?= $sortBy === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc' ?>"
                           style="color: inherit; text-decoration: none;">
                            Customer Name <?= $sortBy === 'name' ? ($sortOrder === 'asc' ? '↑' : '↓') : '' ?>
                        </a>
                    </th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Phone</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Location</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Shop Type</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Sales Rep</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Orders</th>
                    <th style="padding: 12px; text-align: right; font-weight: 600; color: #374151;">Revenue (USD)</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Status</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="9" style="padding: 40px; text-align: center; color: #9ca3af;">
                        No customers found. Try adjusting your filters.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px;">
                        <div style="font-weight: 600; color: #111827;"><?= htmlspecialchars($customer['name']) ?></div>
                        <?php if ($customer['last_order_date']): ?>
                        <div style="font-size: 0.85rem; color: #6b7280;">
                            Last order: <?= date('M d, Y', strtotime($customer['last_order_date'])) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; color: #374151;">
                        <?= $customer['phone'] ? htmlspecialchars($customer['phone']) : '—' ?>
                    </td>
                    <td style="padding: 12px; color: #374151;">
                        <?= $customer['location'] ? htmlspecialchars($customer['location']) : '—' ?>
                    </td>
                    <td style="padding: 12px; color: #374151;">
                        <?= $customer['shop_type'] ? htmlspecialchars($customer['shop_type']) : '—' ?>
                    </td>
                    <td style="padding: 12px; color: #374151;">
                        <?= $customer['sales_rep_name'] ? htmlspecialchars($customer['sales_rep_name']) : '<span style="color: #f59e0b;">Unassigned</span>' ?>
                    </td>
                    <td style="padding: 12px; text-align: center; font-weight: 600; color: #1f6feb;">
                        <?= number_format($customer['order_count']) ?>
                    </td>
                    <td style="padding: 12px; text-align: right; font-weight: 600; color: #10b981;">
                        $<?= number_format($customer['total_revenue_usd'], 2) ?>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <?php if ($customer['is_active']): ?>
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
                        <div style="display: flex; gap: 8px; justify-content: center;">
                            <a href="orders.php?customer=<?= $customer['id'] ?>"
                               class="btn"
                               style="padding: 6px 12px; font-size: 0.85rem;"
                               title="View Orders">
                                Orders
                            </a>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
                                <button type="submit"
                                        class="btn"
                                        style="padding: 6px 12px; font-size: 0.85rem; background: <?= $customer['is_active'] ? '#fef3c7' : '#d1fae5' ?>;"
                                        onclick="return confirm('Are you sure you want to <?= $customer['is_active'] ? 'deactivate' : 'activate' ?> this customer?')">
                                    <?= $customer['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Help Text -->
<div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; color: #6b7280; font-size: 0.9rem;">
    <strong>Tips:</strong>
    <ul style="margin: 8px 0 0 20px;">
        <li>Click "Orders" to view all orders for a customer</li>
        <li>Use filters to find specific customer segments</li>
        <li>Sort by clicking column headers</li>
        <li>Activate/Deactivate customers to control access</li>
    </ul>
</div>

<?php
admin_render_layout_end();
?>
