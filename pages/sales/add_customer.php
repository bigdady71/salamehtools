<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Add New Customer';
$repId = (int)$user['id'];

// Handle customer creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_customer') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid CSRF token');
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $shopType = trim((string)($_POST['shop_type'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $enablePortal = isset($_POST['enable_portal']) && $_POST['enable_portal'] === '1';

        $errors = [];

        // Validation
        if ($name === '') {
            $errors[] = 'Customer name is required.';
        }

        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } else {
            // Check for duplicate phone
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE phone = ?");
            $checkStmt->execute([$phone]);
            if ((int)$checkStmt->fetchColumn() > 0) {
                $errors[] = 'A customer with this phone number already exists.';
            }
        }

        if ($enablePortal) {
            if ($email === '') {
                $errors[] = 'Email is required to enable portal access.';
            } else {
                // Check for duplicate email in users table
                $checkEmailStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $checkEmailStmt->execute([$email]);
                if ((int)$checkEmailStmt->fetchColumn() > 0) {
                    $errors[] = 'A user with this email already exists.';
                }
            }
        }

        if (!empty($errors)) {
            flash('error', 'Please fix the following errors: ' . implode(' ', $errors));
        } else {
            try {
                $pdo->beginTransaction();

                $userId = null;
                $tempPassword = null;

                // Create user account if portal access is enabled
                if ($enablePortal) {
                    // Generate temporary password (phone number for now - easy to remember)
                    $tempPassword = $phone;

                    $userInsertStmt = $pdo->prepare("
                        INSERT INTO users (name, email, phone, password_hash, role, is_active, created_at)
                        VALUES (?, ?, ?, ?, '', 1, NOW())
                    ");

                    $userInsertStmt->execute([
                        $name,
                        $email,
                        $phone,
                        $tempPassword // Plain text for now as requested
                    ]);

                    $userId = (int)$pdo->lastInsertId();
                }

                $insertStmt = $pdo->prepare("
                    INSERT INTO customers (name, phone, location, shop_type, assigned_sales_rep_id, user_id, login_enabled, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");

                $insertStmt->execute([
                    $name,
                    $phone,
                    $location !== '' ? $location : null,
                    $shopType !== '' ? $shopType : null,
                    $repId, // Automatically assign to current sales rep
                    $userId,
                    $enablePortal ? 1 : 0
                ]);

                $pdo->commit();

                if ($enablePortal && $tempPassword) {
                    flash('success', "Customer \"{$name}\" has been successfully created with portal access!<br>
                        <strong>Login URL:</strong> <a href='/salamehtools/pages/login.php' target='_blank'>Customer Login</a><br>
                        <strong>Username:</strong> {$phone} (or {$email})<br>
                        <strong>Temporary Password:</strong> {$tempPassword}<br>
                        <em>Please share these credentials with the customer.</em>");
                } else {
                    flash('success', "Customer \"{$name}\" has been successfully created and assigned to you.");
                }

                header('Location: users.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create customer: " . $e->getMessage());
                flash('error', 'Unable to create customer. Please try again.');
            }
        }
    }
}

$flashes = consume_flashes();

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => '➕ Add New Customer',
    'subtitle' => 'Create a new customer that will be automatically assigned to you',
    'user' => $user,
    'active' => 'users'
]);
?>

<style>
.form-container {
    max-width: 800px;
    background: white;
    border-radius: 12px;
    padding: 32px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 24px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-label {
    font-weight: 600;
    font-size: 0.9rem;
    color: #374151;
}

.form-label.required::after {
    content: ' *';
    color: #ef4444;
}

.form-input,
.form-textarea {
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 100px;
}

.form-help {
    font-size: 0.875rem;
    color: #6b7280;
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid #e5e7eb;
    background: white;
    color: #111827;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.95rem;
}

.btn:hover {
    background: #f3f4f6;
}

.btn-primary {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
}

.btn-primary:hover {
    background: var(--accent-2);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.btn-secondary {
    background: #6b7280;
    border-color: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.alert {
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 24px;
    border-left: 4px solid;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-left-color: #10b981;
    color: #065f46;
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border-left-color: #ef4444;
    color: #991b1b;
}

.info-box {
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 24px;
}

.info-box-title {
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-box-content {
    font-size: 0.9rem;
    color: #1e3a8a;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Flash Messages -->
<?php foreach ($flashes as $msg): ?>
<div class="alert alert-<?= $msg['type'] ?>">
    <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endforeach; ?>

<!-- Info Box -->
<div class="info-box">
    <div class="info-box-title">
        ℹ️ Auto-Assignment
    </div>
    <div class="info-box-content">
        All customers you create will be automatically assigned to you. You can view and manage them in the "My Customers" page.
    </div>
</div>

<!-- Customer Form -->
<div class="form-container">
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_customer">

        <div class="form-grid">
            <!-- Customer Name -->
            <div class="form-group full-width">
                <label class="form-label required" for="name">Customer Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    class="form-input"
                    placeholder="Enter customer name or business name"
                    required
                    autofocus
                    value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Full name or business name of the customer</div>
            </div>

            <!-- Phone Number -->
            <div class="form-group">
                <label class="form-label required" for="phone">Phone Number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    class="form-input"
                    placeholder="+961 XX XXX XXX"
                    required
                    value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Primary contact number (used as default password)</div>
            </div>

            <!-- Email -->
            <div class="form-group">
                <label class="form-label" for="email" id="email-label">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input"
                    placeholder="customer@example.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Required if enabling portal access</div>
            </div>

            <!-- Shop Type -->
            <div class="form-group">
                <label class="form-label" for="shop_type">Shop Type</label>
                <input
                    type="text"
                    id="shop_type"
                    name="shop_type"
                    class="form-input"
                    placeholder="e.g., Grocery, Pharmacy, Restaurant"
                    value="<?= htmlspecialchars($_POST['shop_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-help">Type of business or products they sell</div>
            </div>

            <!-- Location/Address -->
            <div class="form-group full-width">
                <label class="form-label" for="location">Location / Address</label>
                <textarea
                    id="location"
                    name="location"
                    class="form-textarea"
                    placeholder="Enter full address including street, area, city"
                ><?= htmlspecialchars($_POST['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-help">Complete address for delivery and visits</div>
            </div>

            <!-- Enable Portal Access -->
            <div class="form-group full-width">
                <div style="background: rgba(16, 185, 129, 0.05); border: 2px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 20px;">
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; font-weight: 600; font-size: 1rem;">
                        <input
                            type="checkbox"
                            name="enable_portal"
                            id="enable_portal"
                            value="1"
                            style="width: 20px; height: 20px; cursor: pointer;"
                            <?= isset($_POST['enable_portal']) && $_POST['enable_portal'] === '1' ? 'checked' : '' ?>
                        >
                        <span style="color: #065f46;">🌐 Enable Customer Portal Access</span>
                    </label>
                    <div style="margin-left: 32px; margin-top: 8px; font-size: 0.9rem; color: #047857;">
                        Allow this customer to access the online portal to view orders, invoices, make payments, and browse products.
                        <br><strong>Note:</strong> Email is required for portal access. Temporary password will be their phone number.
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Make email required when portal access is enabled
        document.getElementById('enable_portal').addEventListener('change', function() {
            const emailInput = document.getElementById('email');
            const emailLabel = document.getElementById('email-label');
            if (this.checked) {
                emailInput.required = true;
                emailLabel.classList.add('required');
            } else {
                emailInput.required = false;
                emailLabel.classList.remove('required');
            }
        });
        </script>

        <div class="form-actions">
            <a href="users.php" class="btn btn-secondary">
                Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                ✓ Create Customer
            </button>
        </div>
    </form>
</div>

<?php sales_portal_render_layout_end(); ?>
