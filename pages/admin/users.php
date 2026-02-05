<?php

/**
 * Admin user management page: list existing users and provide create/update/toggle actions.
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/admin_page.php';

require_login();
$actor = auth_user();
if (!$actor || ($actor['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Users';
$roles = [
    'admin' => 'Admin',
    'accountant' => 'Accounting',
    'sales_rep' => 'Sales Representative',
    'warehouse' => 'Warehouse',
    'customer' => 'Customer',
    'viewer' => 'Viewer',
];

$pathSegment = $_GET['path'] ?? 'admin/users';
$scriptPath = $_SERVER['PHP_SELF'] ?? '/index.php';
$baseUrl = strtok($scriptPath, '?') ?: '/index.php';
$redirect = static function (array $params = []) use ($pathSegment, $baseUrl): void {
    $query = array_merge(['path' => $pathSegment], $params);
    $location = $baseUrl;
    if ($query) {
        $location .= '?' . http_build_query($query);
    }
    header('Location: ' . $location);
    exit;
};

$formErrors = [];
$formData = [
    'id' => null,
    'name' => '',
    'email' => '',
    'phone' => '',
    'role' => 'sales_rep',
    'permission_level' => 0,
    'is_active' => 1,
    'customer_assigned_sales_rep_id' => '',
    'customer_location' => '',
    'customer_shop_type' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $userId = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? (int)$_POST['user_id'] : null;
        $formData = [
            'id' => $userId,
            'name' => trim($_POST['name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'role' => $_POST['role'] ?? '',
            'permission_level' => (int)filter_var(
                $_POST['permission_level'] ?? 0,
                FILTER_VALIDATE_INT,
                ['options' => ['default' => 0, 'min_range' => 0]]
            ),
            'is_active' => (int)($_POST['is_active'] ?? 0),
            'customer_assigned_sales_rep_id' => trim((string)($_POST['assigned_sales_rep_id'] ?? '')),
            'customer_location' => trim($_POST['customer_location'] ?? ''),
            'customer_shop_type' => trim($_POST['customer_shop_type'] ?? ''),
        ];

        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $updatePassword = false;
        $errors = [];

        if ($formData['name'] === '') {
            $errors[] = 'Name is required.';
        }

        if ($formData['email'] !== '' && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is invalid.';
        }

        if (!isset($roles[$formData['role']])) {
            $errors[] = 'Role selection is invalid.';
        }

        if ($formData['is_active'] !== 0 && $formData['is_active'] !== 1) {
            $formData['is_active'] = 1;
        }

        $assignedSalesRepId = null;
        if ($formData['role'] === 'customer') {
            $repIdStr = $formData['customer_assigned_sales_rep_id'];
            if ($repIdStr === '' || !ctype_digit($repIdStr)) {
                $errors[] = 'Customer accounts must be assigned to a sales representative.';
            } else {
                $assignedSalesRepId = (int)$repIdStr;
            }
        }

        if ($userId === null) {
            if ($password === '') {
                $errors[] = 'Password is required for new users.';
            } elseif (strlen($password) < 4) {
                $errors[] = 'Password must be at least 4 characters.';
            } elseif ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match.';
            }
        } else {
            if ($password !== '') {
                if (strlen($password) < 4) {
                    $errors[] = 'Password must be at least 4 characters.';
                } elseif ($password !== $passwordConfirm) {
                    $errors[] = 'Passwords do not match.';
                } else {
                    $updatePassword = true;
                }
            }
        }

        if ($errors) {
            $formErrors = $errors;
        } else {
            try {
                $pdo->beginTransaction();

                $email = $formData['email'] === '' ? null : $formData['email'];
                $phone = $formData['phone'] === '' ? null : $formData['phone'];

                if ($userId !== null) {
                    $sql = 'UPDATE users SET name = :name, email = :email, phone = :phone, role = :role,
                            permission_level = :permission_level, is_active = :is_active, updated_at = NOW()';
                    $params = [
                        ':name' => $formData['name'],
                        ':email' => $email,
                        ':phone' => $phone,
                        ':role' => $formData['role'],
                        ':permission_level' => $formData['permission_level'],
                        ':is_active' => $formData['is_active'],
                    ];

                    if ($updatePassword) {
                        $sql .= ', password_hash = :password_hash';
                        $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }

                    $sql .= ' WHERE id = :id';
                    $params[':id'] = $userId;

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    if ($formData['role'] === 'customer') {
                        $existingCustomer = $pdo->prepare("
                            SELECT id FROM customers WHERE user_id = :user_id
                        ");
                        $existingCustomer->execute([':user_id' => $userId]);
                        $existingCustomer = $existingCustomer->fetch(PDO::FETCH_ASSOC);

                        if ($existingCustomer) {
                            $updateCustomer = $pdo->prepare("
                                UPDATE customers
                                SET name = :name, phone = :phone, assigned_sales_rep_id = :assigned_sales_rep_id,
                                    location = :location, shop_type = :shop_type, is_active = :is_active, updated_at = NOW()
                                WHERE id = :id
                            ");
                            $updateCustomer->execute([
                                ':name' => $formData['name'],
                                ':phone' => $phone,
                                ':assigned_sales_rep_id' => $assignedSalesRepId,
                                ':location' => $formData['customer_location'],
                                ':shop_type' => $formData['customer_shop_type'],
                                ':is_active' => $formData['is_active'],
                                ':id' => (int)$existingCustomer['id'],
                            ]);
                        } else {
                            $insertCustomer = $pdo->prepare("
                                INSERT INTO customers (user_id, name, phone, assigned_sales_rep_id, location, shop_type, is_active, created_at, updated_at)
                                VALUES (:user_id, :name, :phone, :rep_id, :location, :shop_type, :is_active, NOW(), NOW())
                            ");
                            $insertCustomer->execute([
                                ':user_id' => $userId,
                                ':name' => $formData['name'],
                                ':phone' => $phone,
                                ':rep_id' => $assignedSalesRepId,
                                ':location' => $formData['customer_location'],
                                ':shop_type' => $formData['customer_shop_type'],
                                ':is_active' => $formData['is_active'],
                            ]);
                        }
                    }

                    flash('success', 'User details updated successfully.');
                    $pdo->commit();
                    $redirect(['id' => $userId]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (name, email, phone, role, permission_level, is_active, password_hash, created_at, updated_at)
                         VALUES (:name, :email, :phone, :role, :permission_level, :is_active, :password_hash, NOW(), NOW())'
                    );
                    $stmt->execute([
                        ':name' => $formData['name'],
                        ':email' => $email,
                        ':phone' => $phone,
                        ':role' => $formData['role'],
                        ':permission_level' => $formData['permission_level'],
                        ':is_active' => $formData['is_active'],
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    $newUserId = (int)$pdo->lastInsertId();

                    if ($formData['role'] === 'customer') {
                        $insertCustomer = $pdo->prepare("
                            INSERT INTO customers (user_id, name, phone, assigned_sales_rep_id, location, shop_type, is_active, created_at, updated_at)
                            VALUES (:user_id, :name, :phone, :rep_id, :location, :shop_type, :is_active, NOW(), NOW())
                        ");
                        $insertCustomer->execute([
                            ':user_id' => $newUserId,
                            ':name' => $formData['name'],
                            ':phone' => $phone,
                            ':rep_id' => $assignedSalesRepId,
                            ':location' => $formData['customer_location'],
                            ':shop_type' => $formData['customer_shop_type'],
                            ':is_active' => $formData['is_active'],
                        ]);
                    }

                    flash('success', 'New user created successfully.');
                    $pdo->commit();
                    $redirect();
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('User save failed: ' . $e->getMessage());
                $formErrors[] = 'A database error occurred while saving the user.';
                flash('error', 'Unable to save the user. Please review the details and try again.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Unexpected error while saving user: ' . $e->getMessage());
                $formErrors[] = 'An unexpected error occurred while saving the user.';
                flash('error', 'Something went wrong while saving the user.');
            }
        }
    } elseif ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = (int)($_POST['target_status'] ?? 1);
        $target = $target === 0 ? 0 : 1;

        if ($userId === $actor['id']) {
            flash('error', 'You cannot deactivate your own account while logged in.');
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE users SET is_active = :status, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':status' => $target,
                    ':id' => $userId,
                ]);

                if ($stmt->rowCount() > 0) {
                    $syncFailed = false;
                    try {
                        $customerToggle = $pdo->prepare("
                            UPDATE customers
                            SET is_active = :status, updated_at = NOW()
                            WHERE user_id = :user_id
                        ");
                        $customerToggle->execute([
                            ':status' => $target,
                            ':user_id' => $userId,
                        ]);
                    } catch (PDOException $customerException) {
                        $syncFailed = true;
                    }

                    $message = $target === 1 ? 'User activated.' : 'User deactivated.';
                    flash('success', $message);
                    if ($syncFailed) {
                        flash('warning', 'Linked customer profile could not be updated.');
                    }
                } else {
                    flash('error', 'User not found or no changes applied.');
                }
            } catch (PDOException $e) {
                flash('error', 'Failed to update user status: ' . $e->getMessage());
            }
        }

        $redirect();
    } elseif ($action === 'update_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['role'] ?? '';

        if (!isset($roles[$newRole])) {
            flash('error', 'Invalid role selection.');
            $redirect();
        }

        if ($userId === $actor['id'] && $newRole !== 'admin') {
            flash('error', 'You cannot remove your own admin privileges.');
            $redirect();
        }

        if ($newRole === 'customer') {
            flash('warning', 'Use the edit form to convert this account into a customer so we can capture required details.');
            $redirect(['id' => $userId]);
        }

        try {
            $stmt = $pdo->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':role' => $newRole,
                ':id' => $userId,
            ]);

            if ($stmt->rowCount() > 0) {
                flash('success', 'Role updated successfully.');
            } else {
                flash('error', 'User not found or role unchanged.');
            }
        } catch (PDOException $e) {
            flash('error', 'Failed to update role: ' . $e->getMessage());
        }

        $redirect(['id' => $userId]);
    }
}

$editingUserId = $formData['id'] ?? null;
if (!$formErrors) {
    $editingUserId = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : $editingUserId;

    if ($editingUserId) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, email, phone, role, permission_level, is_active
                 FROM users
                 WHERE id = :id'
            );
            $stmt->execute([':id' => $editingUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $formData = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'] ?? '',
                    'email' => $row['email'] ?? '',
                    'phone' => $row['phone'] ?? '',
                    'role' => $row['role'] ?? 'sales_rep',
                    'permission_level' => (int)($row['permission_level'] ?? 0),
                    'is_active' => (int)($row['is_active'] ?? 0),
                    'customer_assigned_sales_rep_id' => $formData['customer_assigned_sales_rep_id'] ?? '',
                    'customer_location' => $formData['customer_location'] ?? '',
                    'customer_shop_type' => $formData['customer_shop_type'] ?? '',
                ];

                if ($formData['role'] === 'customer') {
                    try {
                        $customerMetaStmt = $pdo->prepare("
                            SELECT assigned_sales_rep_id, location, shop_type
                            FROM customers
                            WHERE user_id = :user_id
                            LIMIT 1
                        ");
                        $customerMetaStmt->execute([':user_id' => $formData['id']]);
                        $customerMeta = $customerMetaStmt->fetch(PDO::FETCH_ASSOC);
                        if ($customerMeta) {
                            $formData['customer_assigned_sales_rep_id'] = (string)($customerMeta['assigned_sales_rep_id'] ?? '');
                            $formData['customer_location'] = $customerMeta['location'] ?? '';
                            $formData['customer_shop_type'] = $customerMeta['shop_type'] ?? '';
                        }
                    } catch (PDOException $e) {
                        flash('error', 'Unable to load customer details for this user.');
                    }
                }
            } else {
                flash('error', 'The requested user could not be found.');
                $redirect();
            }
        } catch (PDOException $e) {
            flash('error', 'Failed to load user details: ' . $e->getMessage());
            $redirect();
        }
    }
}

$users = [];
try {
    $stmt = $pdo->query(
        'SELECT id, name, email, role, is_active, created_at
         FROM users
         ORDER BY created_at DESC'
    );
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    flash('error', 'Unable to load users: ' . $e->getMessage());
}

$salesReps = [];
try {
    $salesRepStmt = $pdo->query("
        SELECT id, name, is_active
        FROM users
        WHERE role = 'sales_rep'
        ORDER BY name
    ");
    $salesReps = $salesRepStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    flash('error', 'Unable to load sales representatives: ' . $e->getMessage());
}

$selectedRepId = $formData['customer_assigned_sales_rep_id'] ?? '';
if ($selectedRepId !== '' && $salesReps) {
    $hasSelected = false;
    foreach ($salesReps as $rep) {
        if ((string)$rep['id'] === (string)$selectedRepId) {
            $hasSelected = true;
            break;
        }
    }
    if (!$hasSelected) {
        $repLookup = $pdo->prepare("
            SELECT id, name, is_active
            FROM users
            WHERE id = :id AND role = 'sales_rep'
            LIMIT 1
        ");
        if ($repLookup->execute([':id' => $selectedRepId])) {
            $repRow = $repLookup->fetch(PDO::FETCH_ASSOC);
            if ($repRow) {
                $salesReps[] = $repRow;
            }
        }
    }
}

$flashes = consume_flashes();

$extraHead = <<<'CSS'
<style>
.users-table-wrap { overflow-x: auto; margin-bottom: 24px; }
.users-table { width: 100%; border-collapse: collapse; }
.users-table th {
    background: var(--bg-panel-alt);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: .05em;
    color: var(--muted);
    padding: 12px 16px;
    text-align: left;
}
.users-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 0.9rem;
}
.users-table tbody tr:hover {
    background: var(--bg-panel-alt);
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.status-active { background: rgba(16, 185, 129, 0.15); color: #059669; }
.status-inactive { background: rgba(239, 68, 68, 0.1); color: #dc2626; }
.action-btns { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.action-btns select {
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
    background: #fff;
}
.form-card {
    background: var(--bg-panel);
    padding: 28px;
    border-radius: 16px;
    border: 1px solid var(--border);
}
.form-card h2 {
    margin: 0 0 20px 0;
    font-size: 1.25rem;
    font-weight: 600;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
}
.form-grid label {
    display: block;
    font-size: 0.85rem;
    margin-bottom: 6px;
    color: var(--muted);
    font-weight: 500;
}
.form-grid input,
.form-grid select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--text);
    font-size: 0.9rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-grid input:focus,
.form-grid select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}
.form-actions {
    margin-top: 24px;
    display: flex;
    gap: 12px;
    align-items: center;
}
.error-list {
    margin: 0 0 20px 0;
    padding: 16px 20px 16px 40px;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 10px;
    color: #dc2626;
}
.error-list li { margin-bottom: 4px; }
.customer-fields { display: contents; }
.customer-fields.hidden { display: none; }
.customer-helper {
    display: block;
    margin-top: 4px;
    font-size: 0.8rem;
    color: var(--muted);
}
</style>
CSS;

admin_render_layout_start([
    'title' => 'User Management',
    'heading' => 'User Management',
    'subtitle' => date('M j, Y · H:i'),
    'user' => $actor,
    'active' => 'users',
    'extra_head' => $extraHead,
]);

admin_render_flashes($flashes);
?>

<!-- Users Table -->
<div class="card" style="margin-bottom: 24px;">
    <h2 style="margin: 0 0 20px 0; font-size: 1.25rem; font-weight: 600;">All Users</h2>
    <div class="users-table-wrap">
        <table class="users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="min-width: 280px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 40px;">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $row): ?>
                        <?php $active = (int)($row['is_active'] ?? 0) === 1; ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td style="font-weight: 500; color: var(--accent);"><?= htmlspecialchars($row['name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['email'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($roles[$row['role']] ?? ucfirst((string)$row['role'])) ?></td>
                            <td>
                                <span class="status-badge <?= $active ? 'status-active' : 'status-inactive' ?>">
                                    <?= $active ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                            <td>
                                <div class="action-btns">
                                    <a href="?id=<?= (int)$row['id'] ?>" class="btn btn-sm">Edit</a>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to change this user\'s status?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="target_status" value="<?= $active ? 0 : 1 ?>">
                                        <button class="btn btn-sm <?= $active ? 'btn-warning' : 'btn-success' ?>" type="submit">
                                            <?= $active ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <form method="post" style="display: inline-flex; align-items: center; gap: 6px;">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                        <select name="role">
                                            <?php foreach ($roles as $value => $label): ?>
                                                <option value="<?= htmlspecialchars($value) ?>" <?= $value === ($row['role'] ?? '') ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm" type="submit">Update Role</button>
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

<!-- Create/Edit User Form -->
<div class="form-card">
    <h2><?= $formData['id'] ? 'Edit User #' . (int)$formData['id'] : 'Create New User' ?></h2>

    <?php if ($formErrors): ?>
        <ul class="error-list">
            <?php foreach ($formErrors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save_user">
        <?php if ($formData['id']): ?>
            <input type="hidden" name="user_id" value="<?= (int)$formData['id'] ?>">
        <?php endif; ?>
        <div class="form-grid">
            <div>
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($formData['name']) ?>">
            </div>
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($formData['email']) ?>">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($formData['phone']) ?>">
            </div>
            <div>
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <?php foreach ($roles as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $value === $formData['role'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="permission_level">Permission Level</label>
                <input type="number" id="permission_level" name="permission_level" min="0" value="<?= (int)$formData['permission_level'] ?>">
            </div>
            <div>
                <label for="is_active">Status</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= (int)$formData['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= (int)$formData['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div>
                <label for="password"><?= $formData['id'] ? 'Reset Password' : 'Password' ?></label>
                <input type="password" id="password" name="password" <?= $formData['id'] ? '' : 'required' ?> autocomplete="new-password">
            </div>
            <div>
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" <?= $formData['id'] ? '' : 'required' ?> autocomplete="new-password">
            </div>
            <div id="customer-extra-fields" class="customer-fields <?= $formData['role'] !== 'customer' ? 'hidden' : '' ?>">
                <div style="grid-column: 1 / -1;">
                    <label for="assigned_sales_rep_id">Assigned Sales Rep</label>
                    <select id="assigned_sales_rep_id" name="assigned_sales_rep_id" data-customer-required="1">
                        <option value="">Select sales rep…</option>
                        <?php foreach ($salesReps as $rep): ?>
                            <option value="<?= (int)$rep['id'] ?>" <?= (string)$rep['id'] === (string)$formData['customer_assigned_sales_rep_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rep['name'] ?? '—') ?>
                                <?php if (isset($rep['is_active']) && !(int)$rep['is_active']): ?>
                                    (inactive)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="customer-helper">Required for customer accounts.</span>
                </div>
                <div>
                    <label for="customer_location">Customer Location</label>
                    <input type="text" id="customer_location" name="customer_location" value="<?= htmlspecialchars($formData['customer_location']) ?>" data-customer-required="1" placeholder="City, area…">
                </div>
                <div>
                    <label for="customer_shop_type">Shop Type</label>
                    <input type="text" id="customer_shop_type" name="customer_shop_type" value="<?= htmlspecialchars($formData['customer_shop_type']) ?>" data-customer-required="1" placeholder="e.g. Hardware, Lighting">
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-success" type="submit"><?= $formData['id'] ? 'Save Changes' : 'Create User' ?></button>
            <?php if ($formData['id']): ?>
                <a class="btn btn-secondary" href="users.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.getElementById('role');
    const customerFields = document.getElementById('customer-extra-fields');
    if (!roleSelect || !customerFields) return;
    
    const dependentInputs = customerFields.querySelectorAll('[data-customer-required]');

    function toggleCustomerFields() {
        const isCustomer = roleSelect.value === 'customer';
        customerFields.classList.toggle('hidden', !isCustomer);
        dependentInputs.forEach(function (input) {
            if (isCustomer) {
                input.setAttribute('required', 'required');
            } else {
                input.removeAttribute('required');
            }
        });
    }

    roleSelect.addEventListener('change', toggleCustomerFields);
    toggleCustomerFields();
});
</script>

<?php admin_render_layout_end(); ?>
