<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';

// Only sales reps can access
guard(['sales_rep']);

$salesRepId = (int)$_SESSION['user']['id'];
$pdo = db();

$success = null;
$error = null;

// Handle reply to message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    $replyText = trim($_POST['reply'] ?? '');

    if ($messageId > 0 && $replyText !== '') {
        try {
            // Get original message details
            $origStmt = $pdo->prepare("
                SELECT customer_id, subject
                FROM customer_messages
                WHERE id = ? AND sales_rep_id = ?
            ");
            $origStmt->execute([$messageId, $salesRepId]);
            $orig = $origStmt->fetch(PDO::FETCH_ASSOC);

            if ($orig) {
                // Insert reply
                $replyStmt = $pdo->prepare("
                    INSERT INTO customer_messages (
                        customer_id,
                        sales_rep_id,
                        subject,
                        message,
                        sender_type,
                        is_read,
                        created_at
                    ) VALUES (?, ?, ?, ?, 'sales_rep', 0, NOW())
                ");
                $replyStmt->execute([
                    $orig['customer_id'],
                    $salesRepId,
                    'Re: ' . $orig['subject'],
                    $replyText
                ]);

                // Mark original as read
                $markReadStmt = $pdo->prepare("UPDATE customer_messages SET is_read = 1 WHERE id = ?");
                $markReadStmt->execute([$messageId]);

                $success = 'Reply sent successfully!';
                header('Location: inbox.php?success=1');
                exit;
            } else {
                $error = 'Message not found.';
            }
        } catch (Exception $e) {
            error_log("Reply failed for sales rep {$salesRepId}: " . $e->getMessage());
            $error = 'Failed to send reply. Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $error = 'Please provide a reply message.';
    }
}

// Handle mark as read
if (isset($_GET['mark_read'])) {
    $messageId = (int)$_GET['mark_read'];
    $markStmt = $pdo->prepare("UPDATE customer_messages SET is_read = 1 WHERE id = ? AND sales_rep_id = ?");
    $markStmt->execute([$messageId, $salesRepId]);
    header('Location: inbox.php');
    exit;
}

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Build query based on filter
$where = "cm.sales_rep_id = ?";
$params = [$salesRepId];

if ($filter === 'unread') {
    $where .= " AND cm.is_read = 0 AND cm.sender_type = 'customer'";
} elseif ($filter === 'from_customers') {
    $where .= " AND cm.sender_type = 'customer'";
}

// Fetch messages
$messagesQuery = "
    SELECT
        cm.id,
        cm.customer_id,
        cm.subject,
        cm.message,
        cm.sender_type,
        cm.is_read,
        cm.created_at,
        c.name as customer_name,
        c.shop_name,
        c.phone as customer_phone
    FROM customer_messages cm
    INNER JOIN customers c ON c.id = cm.customer_id
    WHERE {$where}
    ORDER BY cm.created_at DESC
    LIMIT 100
";

$messagesStmt = $pdo->prepare($messagesQuery);
$messagesStmt->execute($params);
$messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread count
$unreadStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM customer_messages
    WHERE sales_rep_id = ? AND is_read = 0 AND sender_type = 'customer'
");
$unreadStmt->execute([$salesRepId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

$title = 'صندوق رسائل الزبائن - بوابة المبيعات';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
    <style>
        body {
            margin: 0;
            font-family: 'Tajawal', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            margin: 0;
            font-size: 1.5rem;
            color: #0f172a;
        }
        .header .badge {
            background: #dc2626;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 12px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px;
        }
        .filters {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            color: #64748b;
            transition: all 0.2s;
        }
        .filter-btn:hover {
            border-color: #dc2626;
            color: #dc2626;
        }
        .filter-btn.active {
            background: #dc2626;
            border-color: #dc2626;
            color: white;
        }
        .message-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .message-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .message-card.unread {
            border-right: 4px solid #dc2626;
            background: #fef2f2;
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }
        .message-customer {
            font-weight: 600;
            font-size: 1.05rem;
            color: #0f172a;
        }
        .message-shop {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 2px;
        }
        .message-time {
            font-size: 0.85rem;
            color: #94a3b8;
        }
        .message-subject {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .message-body {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        .message-actions {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #dc2626;
            color: white;
        }
        .btn-primary:hover {
            background: #b91c1c;
        }
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        .btn-whatsapp {
            background: #25D366;
            color: white;
        }
        .btn-whatsapp:hover {
            background: #20ba5a;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        .empty-state h3 {
            color: #64748b;
            margin-bottom: 8px;
        }
        .reply-form {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }
        .reply-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 12px;
        }
        .sender-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .sender-customer {
            background: #dbeafe;
            color: #1e40af;
        }
        .sender-sales_rep {
            background: #dcfce7;
            color: #166534;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>
                رسائل الزبائن
                <?php if ($unreadCount > 0): ?>
                    <span class="badge"><?= $unreadCount ?> غير مقروءة</span>
                <?php endif; ?>
            </h1>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">→ العودة للوحة التحكم</a>
    </div>

    <div class="container">
        <?php if (isset($_GET['success'])): ?>
            <div style="background: #dcfce7; color: #166534; padding: 14px 18px; border-radius: 10px; margin-bottom: 24px; border: 1px solid #86efac;">
                تم إرسال الرد بنجاح!
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 14px 18px; border-radius: 10px; margin-bottom: 24px; border: 1px solid #fecaca;">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="filters">
            <a href="inbox.php?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
                جميع الرسائل
            </a>
            <a href="inbox.php?filter=unread" class="filter-btn <?= $filter === 'unread' ? 'active' : '' ?>">
                غير مقروءة (<?= $unreadCount ?>)
            </a>
            <a href="inbox.php?filter=from_customers" class="filter-btn <?= $filter === 'from_customers' ? 'active' : '' ?>">
                من الزبائن
            </a>
        </div>

        <?php if (count($messages) > 0): ?>
            <?php foreach ($messages as $msg): ?>
                <?php
                $isUnread = (int)$msg['is_read'] === 0 && $msg['sender_type'] === 'customer';
                $timeAgo = time() - strtotime($msg['created_at']);
                if ($timeAgo < 3600) {
                    $timeDisplay = 'منذ ' . floor($timeAgo / 60) . ' دقيقة';
                } elseif ($timeAgo < 86400) {
                    $timeDisplay = 'منذ ' . floor($timeAgo / 3600) . ' ساعة';
                } else {
                    $timeDisplay = date('Y/m/d', strtotime($msg['created_at']));
                }

                // Format WhatsApp phone
                $whatsappPhone = preg_replace('/[^0-9+]/', '', $msg['customer_phone'] ?? '');
                if ($whatsappPhone && !str_starts_with($whatsappPhone, '+')) {
                    $whatsappPhone = '+961' . ltrim($whatsappPhone, '0');
                }
                $customerName = htmlspecialchars($msg['customer_name'], ENT_QUOTES, 'UTF-8');
                $whatsappMessage = urlencode("مرحباً {$customerName}، بخصوص: " . $msg['subject']);
                $whatsappLink = $whatsappPhone ? "https://wa.me/{$whatsappPhone}?text={$whatsappMessage}" : null;
                ?>

                <div class="message-card <?= $isUnread ? 'unread' : '' ?>" onclick="toggleReply(<?= $msg['id'] ?>)">
                    <div class="message-header">
                        <div>
                            <div class="message-customer">
                                <?= htmlspecialchars($msg['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                <span class="sender-badge sender-<?= $msg['sender_type'] ?>">
                                    <?= $msg['sender_type'] === 'customer' ? 'زبون' : 'أنت' ?>
                                </span>
                            </div>
                            <?php if ($msg['shop_name']): ?>
                                <div class="message-shop"><?= htmlspecialchars($msg['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="message-time"><?= $timeDisplay ?></div>
                    </div>

                    <div class="message-subject"><?= htmlspecialchars($msg['subject'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="message-body"><?= nl2br(htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8')) ?></div>

                    <div class="message-actions" onclick="event.stopPropagation();">
                        <?php if ($whatsappLink): ?>
                            <a href="<?= $whatsappLink ?>" target="_blank" class="btn btn-whatsapp">
                                💬 WhatsApp
                            </a>
                        <?php endif; ?>
                        <?php if ($msg['sender_type'] === 'customer'): ?>
                            <button onclick="toggleReply(<?= $msg['id'] ?>); event.stopPropagation();" class="btn btn-info">
                                رد
                            </button>
                        <?php endif; ?>
                        <?php if ($isUnread): ?>
                            <a href="inbox.php?mark_read=<?= $msg['id'] ?>" class="btn btn-secondary">
                                تحديد كمقروءة
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($msg['sender_type'] === 'customer'): ?>
                        <div id="reply-form-<?= $msg['id'] ?>" class="reply-form" style="display: none;">
                            <form method="post" action="inbox.php">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                <textarea name="reply" placeholder="اكتب ردك هنا..." required></textarea>
                                <button type="submit" class="btn btn-success">إرسال الرد</button>
                                <button type="button" onclick="toggleReply(<?= $msg['id'] ?>); event.stopPropagation();" class="btn btn-secondary">إلغاء</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <h3>لا توجد رسائل بعد</h3>
                <p>ستظهر رسائل الزبائن هنا</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleReply(messageId) {
            const form = document.getElementById('reply-form-' + messageId);
            if (form) {
                form.style.display = form.style.display === 'none' ? 'block' : 'none';
            }
        }
    </script>
</body>
</html>
