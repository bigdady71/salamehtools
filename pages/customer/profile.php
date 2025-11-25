<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

$success = null;
$error = null;

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters long.';
        } else {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password_hash FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            $currentHash = $stmt->fetchColumn();

            if (!password_verify($currentPassword, $currentHash)) {
                $error = 'Current password is incorrect.';
            } else {
                // Update password
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $customerId]);
                $success = 'Password changed successfully!';
            }
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$title = 'My Profile - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'My Profile',
    'subtitle' => 'View and manage your account information',
    'customer' => $customer,
    'active' => 'profile'
]);

?>

<style>
.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid var(--border);
}
.info-row:last-child {
    border-bottom: none;
}
.info-row .label {
    font-weight: 600;
    color: var(--text);
}
.info-row .value {
    color: var(--muted);
    text-align: right;
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
.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.2s;
}
.form-group input:focus {
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
.tier-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
}
.tier-high {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    color: #ffffff;
}
.tier-medium {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
}
.tier-low {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    color: #ffffff;
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

<div class="profile-grid">
    <!-- Account Information -->
    <div class="card">
        <h2>Account Information</h2>
        <div style="margin-top: 20px;">
            <div class="info-row">
                <span class="label">Customer Name</span>
                <span class="value"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Phone Number</span>
                <span class="value"><?= htmlspecialchars($customer['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Location</span>
                <span class="value"><?= htmlspecialchars($customer['location'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Shop Type</span>
                <span class="value"><?= htmlspecialchars($customer['shop_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Account Tier</span>
                <span class="value">
                    <?php
                    $tier = $customer['customer_tier'] ?? 'medium';
                    $tierClass = 'tier-' . strtolower($tier);
                    ?>
                    <span class="tier-badge <?= $tierClass ?>"><?= ucfirst(htmlspecialchars($tier, ENT_QUOTES, 'UTF-8')) ?></span>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Last Login</span>
                <span class="value">
                    <?php
                    if ($customer['last_login_at']) {
                        echo date('M d, Y g:i A', strtotime($customer['last_login_at']));
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
        </div>
        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border);">
            <p style="margin: 0; color: var(--muted); font-size: 0.88rem;">
                <strong>Need to update your information?</strong><br>
                Please contact your sales representative to update your account details.
            </p>
        </div>
    </div>

    <!-- Sales Representative -->
    <div class="card">
        <h2>Your Sales Representative</h2>
        <?php if ($customer['sales_rep_name']): ?>
            <div style="margin-top: 20px;">
                <div class="info-row">
                    <span class="label">Name</span>
                    <span class="value"><?= htmlspecialchars($customer['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if ($customer['sales_rep_email']): ?>
                    <div class="info-row">
                        <span class="label">Email</span>
                        <span class="value">
                            <a href="mailto:<?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($customer['sales_rep_email'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($customer['sales_rep_phone']): ?>
                    <div class="info-row">
                        <span class="label">Phone</span>
                        <span class="value">
                            <a href="tel:<?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($customer['sales_rep_phone'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <div style="margin-top: 24px;">
                <a href="contact.php" class="btn btn-primary" style="width: 100%; text-align: center;">
                    📞 Contact Sales Rep
                </a>
            </div>
        <?php else: ?>
            <p style="margin-top: 20px; color: var(--muted);">
                No sales representative assigned yet.
            </p>
        <?php endif; ?>
    </div>
</div>

<!-- Change Password -->
<div class="card">
    <h2>Change Password</h2>
    <p style="margin-bottom: 24px;">Update your account password for security.</p>
    <form method="post" action="profile.php" style="max-width: 500px;">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label for="current_password">Current Password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                required
                placeholder="Enter your current password"
            >
        </div>

        <div class="form-group">
            <label for="new_password">New Password</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                required
                minlength="6"
                placeholder="Enter new password (min 6 characters)"
            >
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                required
                minlength="6"
                placeholder="Confirm new password"
            >
        </div>

        <button type="submit" class="btn btn-primary">Update Password</button>
    </form>
</div>

<?php

customer_portal_render_layout_end();
