<?php

/**
 * Admin user management page: list existing users and provide create/update/toggle actions.
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/flash.php';

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
$redirect = static function (array $params = []) use ($pathSegment): void {
    $query = array_merge(['path' => $pathSegment], $params);
    header('Location: /index.php?' . http_build_query($query));
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

        if ($password !== '' || !$userId) {
            if ($password === '') {
                $errors[] = 'Password is required for new users.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if ($password !== $passwordConfirm) {
                $errors[] = 'Password confirmation does not match.';
            }
            $updatePassword = $password !== '';
        }

        if ($userId === $actor['id']) {
            // Prevent self-lockout via status toggle in the edit form.
            $formData['is_active'] = 1;
        }

        if ($errors) {
            $formErrors = $errors;
            flash('error', 'Please correct the form errors and try again.');
        } else {
            $email = $formData['email'] !== '' ? $formData['email'] : null;
            $phone = $formData['phone'] !== '' ? $formData['phone'] : null;

            try {
                if ($userId) {
                    $setParts = [
                        'name = :name',
                        'email = :email',
                        'phone = :phone',
                        'role = :role',
                        'permission_level = :permission_level',
                        'is_active = :is_active',
                    ];

                    $params = [
                        ':id' => $userId,
                        ':name' => $formData['name'],
                        ':email' => $email,
                        ':phone' => $phone,
                        ':role' => $formData['role'],
                        ':permission_level' => $formData['permission_level'],
                        ':is_active' => $formData['is_active'],
                    ];

                    if ($updatePassword) {
                        $setParts[] = 'password_hash = :password_hash';
                        $params[':password_hash'] = $password;
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE users SET ' . implode(', ', $setParts) . ', updated_at = NOW() WHERE id = :id'
                    );
                    $stmt->execute($params);

                    flash('success', 'User details updated successfully.');
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
                        ':password_hash' => $password,
                    ]);

                    flash('success', 'New user created successfully.');
                    $redirect();
                }
            } catch (PDOException $e) {
                $formErrors[] = 'Database error: ' . $e->getMessage();
                flash('error', 'Unable to save the user. Please review the details and try again.');
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
                    $message = $target === 1 ? 'User activated.' : 'User deactivated.';
                    flash('success', $message);
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
                ];
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

$flashes = consume_flashes();

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="../../css/app.css">
    <style>
        body {
            background: #0c1022;
            color: #eaeaea;
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }

        .admin-wrap {
            max-width: 1100px;
            margin: 40px auto;
            padding: 24px;
            background: #1c1f2b;
            border-radius: 10px;
        }

        h1 {
            margin-top: 0;
            font-size: 26px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        th,
        td {
            padding: 10px 12px;
            text-align: left;
        }

        th {
            background: #262a3e;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: .05em;
        }

        tr:nth-child(even) {
            background: #202538;
        }

        tr:nth-child(odd) {
            background: #1b1f30;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
        }

        .status-active {
            background: #00b37a;
            color: #00180f;
        }

        .status-inactive {
            background: #ff4b4b1a;
            color: #ff8b8b;
            border: 1px solid #ff4b4b55;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 6px 12px;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            background: #2f3650;
            color: #eaeaea;
            transition: background .2s;
        }

        .btn:hover {
            background: #3d4665;
        }

        .btn-danger {
            background: #ff4b4b;
            color: #000;
        }

        .btn-danger:hover {
            background: #ff6565;
        }

        .btn-success {
            background: #00ff88;
            color: #001f0d;
        }

        .flash {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .flash-success {
            background: #0f2f24;
            color: #6cf3b6;
        }

        .flash-error {
            background: #3a1212;
            color: #ff9c9c;
        }

        .flash-info {
            background: #1e2e46;
            color: #9ec5ff;
        }

        .flash-warning {
            background: #3a3112;
            color: #ffd27c;
        }

        .form-card {
            margin-top: 32px;
            background: #181c2b;
            padding: 24px;
            border-radius: 8px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            font-size: 14px;
            margin-bottom: 6px;
            color: #9aa1c0;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px;
            border-radius: 6px;
            border: 0;
            background: #262a3e;
            color: #fff;
        }

        .form-actions {
            margin-top: 18px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .inline-form {
            display: inline;
        }

        .error-list {
            margin: 0 0 16px 0;
            padding-left: 20px;
            color: #ff9c9c;
        }

        .error-list li {
            margin-bottom: 4px;
        }

        .table-scroll {
            overflow-x: auto;
            margin-top: 24px;
        }
    </style>
</head>

<body>
    <div class="admin-wrap">
        <h1>User Management</h1>

        <?php foreach ($flashes as $notice): ?>
            <div class="flash flash-<?= htmlspecialchars($notice['type']) ?>">
                <?= htmlspecialchars($notice['message']) ?>
            </div>
        <?php endforeach; ?>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th style="min-width:200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $row): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['email'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($roles[$row['role']] ?? ucfirst((string)$row['role'])) ?></td>
                                <td>
                                    <?php $active = (int)($row['is_active'] ?? 0) === 1; ?>
                                    <span class="status-badge <?= $active ? 'status-active' : 'status-inactive' ?>">
                                        <?= $active ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                                <td>
                                    <div class="actions">
                                        <form method="get" class="inline-form">
                                            <input type="hidden" name="path" value="<?= htmlspecialchars($pathSegment) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button class="btn" type="submit">Edit</button>
                                        </form>
<form method="post" class="inline-form"
      onsubmit="return confirm('Are you sure you want to change this user\'s status?');">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="target_status" value="<?= $active ? 0 : 1 ?>">
    <button class="btn <?= $active ? 'btn-danger' : 'btn-success' ?>" type="submit">
        <?= $active ? 'Deactivate' : 'Activate' ?>
    </button>
</form>

                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                            <select name="role">
                                                <?php foreach ($roles as $value => $label): ?>
                                                    <option value="<?= htmlspecialchars($value) ?>" <?= $value === ($row['role'] ?? '') ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn" type="submit">Update Role</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

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
                </div>
                <div class="form-actions">
                    <button class="btn btn-success" type="submit"><?= $formData['id'] ? 'Save Changes' : 'Create User' ?></button>
                    <?php if ($formData['id']): ?>
                        <a class="btn" href="/index.php?path=<?= htmlspecialchars($pathSegment) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</body>

</html>
