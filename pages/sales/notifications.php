<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$pdo = db();
$salesRepId = (int)$user['id'];

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_read') {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $salesRepId]);
        }
    } elseif ($_POST['action'] === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$salesRepId]);
        $_SESSION['success'] = 'All notifications marked as read.';
    } elseif ($_POST['action'] === 'delete') {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $salesRepId]);
        }
    } elseif ($_POST['action'] === 'clear_all') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        $stmt->execute([$salesRepId]);
        $_SESSION['success'] = 'Read notifications cleared.';
    }

    header('Location: notifications.php');
    exit;
}

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Build query
$where = "user_id = ?";
$params = [$salesRepId];

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter === 'order_ready') {
    $where .= " AND type = 'order_ready'";
}

// Fetch notifications
$notificationsQuery = "
    SELECT *
    FROM notifications
    WHERE {$where}
    ORDER BY created_at DESC
    LIMIT 100
";

$stmt = $pdo->prepare($notificationsQuery);
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread count
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$salesRepId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

// Notification type configurations
$typeConfig = [
    'order_ready' => [
        'icon' => '📦',
        'color' => '#22c55e',
        'bgColor' => '#dcfce7',
        'label' => 'Order Ready'
    ],
    'stock_approved' => [
        'icon' => '✅',
        'color' => '#3b82f6',
        'bgColor' => '#dbeafe',
        'label' => 'Stock Approved'
    ],
    'stock_rejected' => [
        'icon' => '❌',
        'color' => '#dc2626',
        'bgColor' => '#fee2e2',
        'label' => 'Stock Rejected'
    ],
    'order_cancelled' => [
        'icon' => '🚫',
        'color' => '#dc2626',
        'bgColor' => '#fee2e2',
        'label' => 'Order Cancelled'
    ],
    'system' => [
        'icon' => '🔔',
        'color' => '#6366f1',
        'bgColor' => '#e0e7ff',
        'label' => 'System'
    ]
];

sales_portal_render_layout_start(t('notifications.title', 'Notifications'));
?>

<style>
.notif-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.notif-header h1 {
    margin: 0;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    gap: 12px;
}
.notif-badge {
    background: #dc2626;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.notif-actions {
    display: flex;
    gap: 10px;
}
.notif-actions form {
    display: inline;
}
.filters {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.filter-btn {
    padding: 10px 20px;
    border: 2px solid var(--border);
    background: var(--card);
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    color: var(--muted);
    transition: all 0.2s;
}
.filter-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}
.filter-btn.active {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
}
.notif-card {
    background: var(--card);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 12px;
    border: 1px solid var(--border);
    transition: all 0.2s;
    display: flex;
    gap: 16px;
    align-items: flex-start;
}
.notif-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.notif-card.unread {
    border-left: 4px solid var(--accent);
    background: rgba(220, 38, 38, 0.05);
}
.notif-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}
.notif-content {
    flex: 1;
    min-width: 0;
}
.notif-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 8px;
}
.notif-type {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}
.notif-time {
    font-size: 0.85rem;
    color: var(--muted);
    white-space: nowrap;
}
.notif-message {
    font-size: 0.95rem;
    color: var(--text);
    line-height: 1.5;
    margin-bottom: 12px;
}
.notif-message strong {
    color: var(--text);
}
.notif-btn-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.notif-btn {
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.notif-btn-primary {
    background: var(--accent);
    color: white;
}
.notif-btn-primary:hover {
    opacity: 0.9;
}
.notif-btn-secondary {
    background: var(--border);
    color: var(--text);
}
.notif-btn-secondary:hover {
    background: #d1d5db;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.empty-state .icon {
    font-size: 4rem;
    margin-bottom: 16px;
}
.empty-state h3 {
    color: var(--text);
    margin-bottom: 8px;
}
</style>

<?php if (isset($_SESSION['success'])): ?>
    <div style="background:#d1fae5;color:#065f46;padding:14px 18px;border-radius:10px;margin-bottom:24px;">
        <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div class="notif-header">
    <h1>
        <?= t('notifications.title', 'Notifications') ?>
        <?php if ($unreadCount > 0): ?>
            <span class="notif-badge"><?= $unreadCount ?> <?= t('notifications.unread', 'unread') ?></span>
        <?php endif; ?>
    </h1>

    <div class="notif-actions">
        <?php if ($unreadCount > 0): ?>
            <form method="POST">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="notif-btn notif-btn-secondary">
                    ✓ <?= t('notifications.mark_all_read', 'Mark All Read') ?>
                </button>
            </form>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Clear all read notifications?');">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="notif-btn notif-btn-secondary">
                🗑️ <?= t('notifications.clear_read', 'Clear Read') ?>
            </button>
        </form>
    </div>
</div>

<div class="filters">
    <a href="notifications.php?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
        <?= t('notifications.all', 'All') ?>
    </a>
    <a href="notifications.php?filter=unread" class="filter-btn <?= $filter === 'unread' ? 'active' : '' ?>">
        <?= t('notifications.unread', 'Unread') ?> (<?= $unreadCount ?>)
    </a>
    <a href="notifications.php?filter=order_ready" class="filter-btn <?= $filter === 'order_ready' ? 'active' : '' ?>">
        📦 <?= t('notifications.orders_ready', 'Orders Ready') ?>
    </a>
</div>

<?php if (count($notifications) > 0): ?>
    <?php foreach ($notifications as $notif): ?>
        <?php
        $isUnread = (int)$notif['is_read'] === 0;
        $type = $notif['type'] ?? 'system';
        $config = $typeConfig[$type] ?? $typeConfig['system'];

        // Parse payload
        $payload = json_decode($notif['payload'] ?? '{}', true) ?: [];
        $message = $payload['message'] ?? $notif['message'] ?? 'New notification';
        $orderId = $payload['order_id'] ?? null;
        $orderNumber = $payload['order_number'] ?? null;

        // Time display
        $timeAgo = time() - strtotime($notif['created_at']);
        if ($timeAgo < 60) {
            $timeDisplay = t('notifications.just_now', 'Just now');
        } elseif ($timeAgo < 3600) {
            $mins = floor($timeAgo / 60);
            $timeDisplay = $mins . ' ' . t('notifications.minutes_ago', 'min ago');
        } elseif ($timeAgo < 86400) {
            $hours = floor($timeAgo / 3600);
            $timeDisplay = $hours . ' ' . t('notifications.hours_ago', 'hours ago');
        } else {
            $timeDisplay = date('M j, Y H:i', strtotime($notif['created_at']));
        }
        ?>

        <div class="notif-card <?= $isUnread ? 'unread' : '' ?>">
            <div class="notif-icon" style="background:<?= $config['bgColor'] ?>;">
                <?= $config['icon'] ?>
            </div>

            <div class="notif-content">
                <div class="notif-top">
                    <span class="notif-type" style="background:<?= $config['bgColor'] ?>;color:<?= $config['color'] ?>;">
                        <?= $config['label'] ?>
                    </span>
                    <span class="notif-time"><?= $timeDisplay ?></span>
                </div>

                <div class="notif-message">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($orderNumber): ?>
                        <br><strong><?= t('notifications.order', 'Order') ?>: <?= htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php endif; ?>
                </div>

                <div class="notif-btn-group">
                    <?php if ($type === 'order_ready' && $orderId): ?>
                        <a href="accept_orders.php" class="notif-btn notif-btn-primary">
                            📥 <?= t('notifications.accept_order', 'Accept Order') ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($isUnread): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                            <button type="submit" class="notif-btn notif-btn-secondary">
                                ✓ <?= t('notifications.mark_read', 'Mark Read') ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                        <button type="submit" class="notif-btn notif-btn-secondary">
                            🗑️
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">
        <div class="icon">🔔</div>
        <h3><?= t('notifications.no_notifications', 'No notifications') ?></h3>
        <p><?= t('notifications.empty_message', 'You\'re all caught up! New notifications will appear here.') ?></p>
    </div>
<?php endif; ?>

<?php
sales_portal_render_layout_end();
