
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();

$pdo = db();
$currentUser = current_user();

$error = '';
$success = '';

// Get available roles.
$roleQuery = $pdo->query("
    SELECT id, name
    FROM roles
    ORDER BY name ASC
");

$roles = $roleQuery->fetchAll(PDO::FETCH_ASSOC);

// Helper: check if a role is admin.
function isAdminRoleName($roleName): bool
{
    return strtolower(trim((string)$roleName)) === 'admin';
}

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    // CREATE USER
    if ($action === 'create') {

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = filter_var($_POST['role_id'] ?? '', FILTER_VALIDATE_INT);

        $active = isset($_POST['is_active']) ? 1 : 0;

        if (
            $firstName === '' ||
            $lastName === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !$roleId ||
            strlen($password) < 8
        ) {
            $error = 'Complete all fields. Use a valid email and a password with at least 8 characters.';
        } else {

            $roleCheck = $pdo->prepare("
                SELECT id FROM roles WHERE id = ? LIMIT 1
            ");
            $roleCheck->execute([$roleId]);

            if (!$roleCheck->fetchColumn()) {
                $error = 'Please select a valid role.';
            } else {

                $emailCheck = $pdo->prepare("
                    SELECT id FROM users WHERE email = ? LIMIT 1
                ");
                $emailCheck->execute([$email]);

                if ($emailCheck->fetchColumn()) {
                    $error = 'That email address is already registered.';
                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO users
                            (first_name, last_name, email, password, role_id, is_active)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $firstName,
                        $lastName,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $roleId,
                        $active
                    ]);

                    $newUserId = (int)$pdo->lastInsertId();

                    log_audit(
                        $pdo,
                        (int)$currentUser['id'],
                        'User created',
                        'Created account for ' . $email
                    );

                    flash('success', 'User account created successfully.');

                    redirect('users.php');
                }
            }
        }
    }

    // UPDATE USER
    elseif ($action === 'update') {

        $userId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = filter_var($_POST['role_id'] ?? '', FILTER_VALIDATE_INT);

        $active = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['password'] ?? '';

        if (
            !$userId ||
            $firstName === '' ||
            $lastName === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !$roleId
        ) {
            $error = 'Please complete all required fields.';
        } elseif ($userId === (int)$currentUser['id'] && !$active) {
            $error = 'You cannot deactivate your own account.';
        } else {

            $existingQuery = $pdo->prepare("
                SELECT u.id, u.email, u.role_id, u.is_active, r.name AS role
                FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                LIMIT 1
            ");

            $existingQuery->execute([$userId]);
            $existingUser = $existingQuery->fetch(PDO::FETCH_ASSOC);

            $roleCheck = $pdo->prepare("
                SELECT name FROM roles WHERE id = ? LIMIT 1
            ");
            $roleCheck->execute([$roleId]);
            $newRoleName = $roleCheck->fetchColumn();

            if (!$existingUser || !$newRoleName) {
                $error = 'User account or selected role was not found.';
            } elseif ($userId === (int)$currentUser['id'] && !isAdminRoleName($newRoleName)) {
                $error = 'You cannot remove your own administrator role.';
            } else {

                // Prevent duplicate email addresses.
                $emailCheck = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                      AND id != ?
                    LIMIT 1
                ");

                $emailCheck->execute([$email, $userId]);

                if ($emailCheck->fetchColumn()) {
                    $error = 'That email address is already used by another account.';
                } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
                    $error = 'New password must contain at least 8 characters.';
                } else {

                    // Protect the last active administrator.
                    $removingAdmin =
                        isAdminRoleName($existingUser['role'] ?? '') &&
                        (
                            !isAdminRoleName($newRoleName) ||
                            !$active
                        );

                    if ($removingAdmin) {

                        $adminCount = $pdo->query("
                            SELECT COUNT(*)
                            FROM users u
                            INNER JOIN roles r ON r.id = u.role_id
                            WHERE LOWER(r.name) = 'admin'
                              AND u.is_active = 1
                        ")->fetchColumn();

                        if ((int)$adminCount <= 1) {
                            $error = 'You cannot deactivate or change the role of the last active administrator.';
                        }
                    }

                    if ($error === '') {

                        if ($newPassword !== '') {

                            $stmt = $pdo->prepare("
                                UPDATE users
                                SET first_name = ?,
                                    last_name = ?,
                                    email = ?,
                                    password = ?,
                                    role_id = ?,
                                    is_active = ?
                                WHERE id = ?
                            ");

                            $stmt->execute([
                                $firstName,
                                $lastName,
                                $email,
                                password_hash($newPassword, PASSWORD_DEFAULT),
                                $roleId,
                                $active,
                                $userId
                            ]);

                        } else {

                            $stmt = $pdo->prepare("
                                UPDATE users
                                SET first_name = ?,
                                    last_name = ?,
                                    email = ?,
                                    role_id = ?,
                                    is_active = ?
                                WHERE id = ?
                            ");

                            $stmt->execute([
                                $firstName,
                                $lastName,
                                $email,
                                $roleId,
                                $active,
                                $userId
                            ]);
                        }

                        log_audit(
                            $pdo,
                            (int)$currentUser['id'],
                            'User updated',
                            'Updated account for ' . $email
                        );

                        flash('success', 'User account updated successfully.');

                        redirect('users.php');
                    }
                }
            }
        }
    }

    // ACTIVATE / DEACTIVATE USER
    elseif ($action === 'toggle') {

        $userId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);

        if (!$userId) {
            $error = 'Invalid user account.';
        } elseif ($userId === (int)$currentUser['id']) {
            $error = 'You cannot deactivate your own account.';
        } else {

            $stmt = $pdo->prepare("
                SELECT u.id, u.is_active, u.email, r.name AS role
                FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                LIMIT 1
            ");

            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $error = 'User account not found.';
            } else {

                $newStatus = (int)$targetUser['is_active'] === 1 ? 0 : 1;

                if (
                    $newStatus === 0 &&
                    isAdminRoleName($targetUser['role'] ?? '')
                ) {
                    $adminCount = $pdo->query("
                        SELECT COUNT(*)
                        FROM users u
                        INNER JOIN roles r ON r.id = u.role_id
                        WHERE LOWER(r.name) = 'admin'
                          AND u.is_active = 1
                    ")->fetchColumn();

                    if ((int)$adminCount <= 1) {
                        $error = 'You cannot deactivate the last active administrator.';
                    }
                }

                if ($error === '') {

                    $update = $pdo->prepare("
                        UPDATE users
                        SET is_active = ?
                        WHERE id = ?
                    ");

                    $update->execute([$newStatus, $userId]);

                    log_audit(
                        $pdo,
                        (int)$currentUser['id'],
                        $newStatus ? 'User activated' : 'User deactivated',
                        $targetUser['email']
                    );

                    flash(
                        'success',
                        $newStatus
                            ? 'User account activated.'
                            : 'User account deactivated.'
                    );

                    redirect('users.php');
                }
            }
        }
    }
}

// Load account selected for editing.
$editUser = null;

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId) {

    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, role_id, is_active
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Load all user accounts.
$usersQuery = $pdo->query("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.role_id,
        u.is_active,
        r.name AS role
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    ORDER BY u.id DESC
");

$users = $usersQuery->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'User Management';

require __DIR__ . '/../includes/header.php';
?>

<div class="section-heading">
    <div>
        <h2>User Management</h2>
        <p class="muted">
            Manage registered accounts, roles, and account access.
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- ADD / EDIT USER FORM -->
<div class="form-card">

    <div class="card-heading">
        <h2>
            <?= $editUser ? 'Edit User Account' : 'Create New User' ?>
        </h2>

        <p class="muted">
            <?= $editUser
                ? 'Update the selected user account.'
                : 'Register a new account in the help desk system.' ?>
        </p>
    </div>

    <form method="POST" class="form-stack">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="<?= $editUser ? 'update' : 'create' ?>"
        >

        <?php if ($editUser): ?>
            <input
                type="hidden"
                name="user_id"
                value="<?= (int)$editUser['id'] ?>"
            >
        <?php endif; ?>

        <div class="form-row">

            <label>
                First Name

                <input
                    type="text"
                    name="first_name"
                    required
                    maxlength="100"
                    value="<?= e($editUser['first_name'] ?? '') ?>"
                    placeholder="Enter first name"
                >
            </label>

            <label>
                Last Name

                <input
                    type="text"
                    name="last_name"
                    required
                    maxlength="100"
                    value="<?= e($editUser['last_name'] ?? '') ?>"
                    placeholder="Enter last name"
                >
            </label>

        </div>

        <label>
            Email Address

            <input
                type="email"
                name="email"
                required
                value="<?= e($editUser['email'] ?? '') ?>"
                placeholder="name@example.com"
            >
        </label>

        <div class="form-row">

            <label>
                Role

                <select name="role_id" required>

                    <option value="">
                        Select Role
                    </option>

                    <?php foreach ($roles as $role): ?>

                        <option
                            value="<?= (int)$role['id'] ?>"
                            <?= isset($editUser['role_id']) &&
                                (int)$editUser['role_id'] === (int)$role['id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(ucwords(str_replace('_', ' ', $role['name']))) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </label>

            <label>
                <?= $editUser
                    ? 'New Password (Optional)'
                    : 'Password' ?>

                <input
                    type="password"
                    name="password"
                    <?= $editUser ? '' : 'required' ?>
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="<?= $editUser
                        ? 'Leave blank to keep current password'
                        : 'At least 8 characters' ?>"
                >
            </label>

        </div>

        <label class="checkbox-label">
            <input
                type="checkbox"
                name="is_active"
                value="1"
                <?= !isset($editUser['is_active']) ||
                    (int)$editUser['is_active'] === 1
                    ? 'checked'
                    : '' ?>
            >

            Active Account
        </label>

        <div class="form-actions">

            <button type="submit" class="btn primary">
                <?= $editUser ? 'Save Changes' : 'Create Account' ?>
            </button>

            <?php if ($editUser): ?>

                <a href="users.php" class="btn secondary">
                    Cancel
                </a>

            <?php endif; ?>

        </div>

    </form>

</div>

<!-- USERS TABLE -->
<div class="table-card">

    <div class="card-heading">
        <h2>Registered Users</h2>

        <p class="muted">
            <?= count($users) ?> account(s) found
        </p>
    </div>

    <div class="table-scroll">

        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Account Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

                <?php if (empty($users)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No user accounts found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($users as $account): ?>

                        <tr>

                            <td>
                                <?= (int)$account['id'] ?>
                            </td>

                            <td>
                                <strong>
                                    <?= e(trim(
                                        $account['first_name'] . ' ' .
                                        $account['last_name']
                                    )) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e($account['email']) ?>
                            </td>

                            <td>
                                <?= e(ucwords(str_replace(
                                    '_',
                                    ' ',
                                    $account['role'] ?? 'Unknown'
                                ))) ?>
                            </td>

                            <td>

                                <?php if ((int)$account['is_active'] === 1): ?>

                                    <span class="status">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="priority low">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <div class="form-actions">

                                    <a
                                        href="users.php?edit=<?= (int)$account['id'] ?>"
                                        class="text-link"
                                    >
                                        Edit
                                    </a>

                                    <?php if ((int)$account['id'] !== (int)$currentUser['id']): ?>

                                        <form method="POST" style="display:inline">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int)$account['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="text-link"
                                                onclick="return confirm('Are you sure you want to change this account status?')"
                                            >
                                                <?= (int)$account['is_active'] === 1
                                                    ? 'Deactivate'
                                                    : 'Activate' ?>
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
