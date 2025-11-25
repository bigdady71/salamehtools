<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();
$salesRepId = (int)($customer['assigned_sales_rep_id'] ?? 0);

$success = null;
$error = null;

// Handle message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($subject === '' || $message === '') {
        $error = 'Please provide both subject and message.';
    } elseif ($salesRepId === 0) {
        $error = 'No sales representative assigned to your account.';
    } else {
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO customer_messages (
                    customer_id,
                    sales_rep_id,
                    subject,
                    message,
                    sender_type,
                    is_read,
                    created_at
                ) VALUES (?, ?, ?, ?, 'customer', 0, NOW())
            ");
            $insertStmt->execute([$customerId, $salesRepId, $subject, $message]);
            $success = 'Your message has been sent successfully! Your sales representative will respond shortly.';
            // Clear form
            $_POST = [];
        } catch (Exception $e) {
            $error = 'Failed to send message. Please try again or contact your sales rep directly.';
        }
    }
}

// Fetch recent messages (last 10)
$messagesStmt = $pdo->prepare("
    SELECT
        cm.id,
        cm.subject,
        cm.message,
        cm.sender_type,
        cm.is_read,
        cm.created_at,
        u.name as sales_rep_name
    FROM customer_messages cm
    LEFT JOIN users u ON u.id = cm.sales_rep_id
    WHERE cm.customer_id = ?
    ORDER BY cm.created_at DESC
    LIMIT 10
");
$messagesStmt->execute([$customerId]);
$messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Contact Sales Rep - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Contact Your Sales Representative',
    'subtitle' => 'Send a message or view contact information',
    'customer' => $customer,
    'active' => 'contact'
]);

?>

<style>
.contact-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .contact-grid {
        grid-template-columns: 1fr;
    }
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 0.92rem;
    color: var(--text);
}
.form-group input,
.form-group textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    font-family: inherit;
    transition: all 0.2s;
}
.form-group textarea {
    resize: vertical;
    min-height: 150px;
}
.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 24px;
    font-size: 0.92rem;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.contact-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--bg-panel-alt);
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 12px;
}
.contact-info .icon {
    font-size: 1.5rem;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-panel);
    border-radius: 50%;
    flex-shrink: 0;
}
.contact-info .details {
    flex: 1;
}
.contact-info .details .label {
    font-size: 0.8rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px;
}
.contact-info .details .value {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}
.message-item {
    padding: 16px;
    background: var(--bg-panel-alt);
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 12px;
}
.message-item:last-child {
    margin-bottom: 0;
}
.message-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 8px;
}
.message-subject {
    font-weight: 600;
    color: var(--text);
    font-size: 1rem;
    margin: 0;
}
.message-meta {
    font-size: 0.8rem;
    color: var(--muted);
}
.message-body {
    color: var(--muted);
    line-height: 1.6;
    margin: 12px 0 0;
}
.sender-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 8px;
}
.sender-customer {
    background: #dbeafe;
    color: #1e40af;
}
.sender-sales_rep {
    background: #d1fae5;
    color: #065f46;
}
.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: var(--muted);
}
.empty-state h3 {
    font-size: 1.2rem;
    margin: 0 0 12px;
    color: var(--text);
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="contact-grid">
    <!-- Contact Form -->
    <div class="card">
        <h2>Send a Message</h2>
        <p style="margin: 0 0 24px; color: var(--muted); font-size: 0.9rem;">
            Have a question or need assistance? Send a message to your sales representative.
        </p>

        <form method="post" action="contact.php">
            <input type="hidden" name="action" value="send_message">

            <div class="form-group">
                <label for="subject">Subject</label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    required
                    placeholder="Brief description of your inquiry..."
                    value="<?= htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="message">Message</label>
                <textarea
                    id="message"
                    name="message"
                    required
                    placeholder="Describe your question or request in detail..."
                ><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                Send Message
            </button>
        </form>
    </div>

    <!-- Sales Rep Contact Info -->
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <h2>Sales Representative</h2>
            <?php if ($customer['sales_rep_name']): ?>
                <div style="margin-top: 20px;">
                    <div class="contact-info">
                        <div class="icon">👤</div>
                        <div class="details">
                            <p class="label">Name</p>
                            <p class="value"><?= htmlspecialchars($customer['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>

                    <?php if ($customer['sales_rep_email']): ?>
                        <div class="contact-info">
                            <div class="icon">📧</div>
                            <div class="details">
                                <p class="label">Email</p>
                                <p class="value">
                                    <a href="mailto:<?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>" style="color: var(--accent);">
                                        <?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($customer['sales_rep_phone']): ?>
                        <div class="contact-info">
                            <div class="icon">📞</div>
                            <div class="details">
                                <p class="label">Phone</p>
                                <p class="value">
                                    <a href="tel:<?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>" style="color: var(--accent);">
                                        <?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p style="margin: 20px 0 0; color: var(--muted);">
                    No sales representative assigned yet.
                </p>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <h2>Quick Actions</h2>
            <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 12px;">
                <?php if ($customer['sales_rep_phone']): ?>
                    <a href="tel:<?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary" style="width: 100%; text-align: center;">
                        📞 Call Now
                    </a>
                <?php endif; ?>
                <?php if ($customer['sales_rep_email']): ?>
                    <a href="mailto:<?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>" class="btn" style="width: 100%; text-align: center;">
                        📧 Send Email
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Message History -->
<div class="card">
    <h2>Message History</h2>
    <?php if (count($messages) > 0): ?>
        <div style="margin-top: 20px;">
            <?php foreach ($messages as $msg): ?>
                <?php
                $subject = htmlspecialchars($msg['subject'], ENT_QUOTES, 'UTF-8');
                $body = htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8');
                $senderType = htmlspecialchars($msg['sender_type'], ENT_QUOTES, 'UTF-8');
                $senderDisplay = $senderType === 'customer' ? 'You' : $msg['sales_rep_name'];
                $sentDate = date('M d, Y g:i A', strtotime($msg['created_at']));
                $badgeClass = 'sender-' . $senderType;
                ?>
                <div class="message-item">
                    <div class="message-header">
                        <div>
                            <p class="message-subject">
                                <?= $subject ?>
                                <span class="sender-badge <?= $badgeClass ?>"><?= $senderDisplay ?></span>
                            </p>
                            <p class="message-meta"><?= $sentDate ?></p>
                        </div>
                    </div>
                    <div class="message-body">
                        <?= nl2br($body) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>💬 No Messages Yet</h3>
            <p>Start a conversation by sending a message above.</p>
        </div>
    <?php endif; ?>
</div>

<?php

customer_portal_render_layout_end();
