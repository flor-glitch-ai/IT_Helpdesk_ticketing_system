<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

require_admin();

$pdo         = db();
$currentUser = current_user();
$currentId   = (int) $currentUser['id'];
$error       = '';

/* ---------- Helpers ---------- */

if (!function_exists('isAdminRoleName')) {
    function isAdminRoleName($roleName): bool
    {
        return strtolower(trim((string) $roleName)) === 'admin';
    }
}

function active_admin_count(PDO $pdo): int
{
    return (int) $pdo->query("
        SELECT COUNT(*)
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE LOWER(r.name) = 'admin' AND u.is_active = 1
    ")->fetchColumn();
}

$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Form handling ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    /* CREATE */
    if ($action === 'create') {

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $password  = $_POST['password'] ?? '';
        $roleId    = filter_var($_POST['role_id'] ?? '', FILTER_VALIDATE_INT);
        $active    = isset($_POST['is_active']) ? 1 : 0;

        if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$roleId || strlen($password) < 8) {
            $error = 'Complete all fields. Use a valid email and a password with at least 8 characters.';
        } else {
            $roleCheck = $pdo->prepare("SELECT id FROM roles WHERE id = ? LIMIT 1");
            $roleCheck->execute([$roleId]);

            $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $emailCheck->execute([$email]);

            if (!$roleCheck->fetchColumn()) {
                $error = 'Please select a valid role.';
            } elseif ($emailCheck->fetchColumn()) {
                $error = 'That email address is already registered.';
            } else {
                $pdo->prepare("
                    INSERT INTO users (first_name, last_name, email, password, role_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT), $roleId, $active]);

                log_audit($pdo, $currentId, 'User created', 'Created account for ' . $email);
                flash('success', 'User account created successfully.');
                redirect('users.php');
            }
        }
    }

    /* UPDATE */
    elseif ($action === 'update') {

        $userId      = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
        $firstName   = trim($_POST['first_name'] ?? '');
        $lastName    = trim($_POST['last_name'] ?? '');
        $email       = strtolower(trim($_POST['email'] ?? ''));
        $roleId      = filter_var($_POST['role_id'] ?? '', FILTER_VALIDATE_INT);
        $active      = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['password'] ?? '';

        if (!$userId || $firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$roleId) {
            $error = 'Please complete all required fields.';
        } elseif ($userId === $currentId && !$active) {
            $error = 'You cannot deactivate your own account.';
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            $error = 'New password must contain at least 8 characters.';
        } else {
            $existingQuery = $pdo->prepare("
                SELECT u.id, r.name AS role
                FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $existingQuery->execute([$userId]);
            $existingUser = $existingQuery->fetch(PDO::FETCH_ASSOC);

            $roleCheck = $pdo->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1");
            $roleCheck->execute([$roleId]);
            $newRoleName = $roleCheck->fetchColumn();

            $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $emailCheck->execute([$email, $userId]);

            if (!$existingUser || !$newRoleName) {
                $error = 'User account or selected role was not found.';
            } elseif ($userId === $currentId && !isAdminRoleName($newRoleName)) {
                $error = 'You cannot remove your own administrator role.';
            } elseif ($emailCheck->fetchColumn()) {
                $error = 'That email address is already used by another account.';
            } else {
                // Protect the last active administrator.
                $removingAdmin = isAdminRoleName($existingUser['role'] ?? '')
                    && (!isAdminRoleName($newRoleName) || !$active);

                if ($removingAdmin && active_admin_count($pdo) <= 1) {
                    $error = 'You cannot deactivate or change the role of the last active administrator.';
                }
            }

            if ($error === '') {
                $fields = ['first_name = ?', 'last_name = ?', 'email = ?', 'role_id = ?', 'is_active = ?'];
                $values = [$firstName, $lastName, $email, $roleId, $active];

                if ($newPassword !== '') {
                    $fields[] = 'password = ?';
                    $values[] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $values[] = $userId;

                $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

                log_audit($pdo, $currentId, 'User updated', 'Updated account for ' . $email);
                flash('success', 'User account updated successfully.');
                redirect('users.php');
            }
        }
    }

    /* TOGGLE ACTIVE */
    elseif ($action === 'toggle') {

        $userId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);

        if (!$userId) {
            $error = 'Invalid user account.';
        } elseif ($userId === $currentId) {
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
                $newStatus = (int) $targetUser['is_active'] === 1 ? 0 : 1;

                if ($newStatus === 0 && isAdminRoleName($targetUser['role'] ?? '') && active_admin_count($pdo) <= 1) {
                    $error = 'You cannot deactivate the last active administrator.';
                } else {
                    $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newStatus, $userId]);

                    log_audit($pdo, $currentId, $newStatus ? 'User activated' : 'User deactivated', $targetUser['email']);
                    flash('success', $newStatus ? 'User account activated.' : 'User account deactivated.');
                    redirect('users.php');
                }
            }
        }
    }
}

/* ---------- Form state ----------
   A failed submit must keep what the admin typed and stay in the same mode
   (previously a failed *update* re-rendered as a blank "Create" form). */

$formMode   = 'create';
$editingId  = null;
$formValues = ['first_name' => '', 'last_name' => '', 'email' => '', 'role_id' => '', 'is_active' => 1];

$postedAction = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '' && in_array($postedAction, ['create', 'update'], true)) {

    $formMode  = $postedAction;
    $editingId = $postedAction === 'update' ? (int) ($_POST['user_id'] ?? 0) : null;

    $formValues = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'role_id'    => $_POST['role_id'] ?? '',
        'is_active'  => isset($_POST['is_active']) ? 1 : 0,
    ];

} elseif ($editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT)) {

    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, role_id, is_active
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editUser) {
        $formMode   = 'update';
        $editingId  = (int) $editUser['id'];
        $formValues = $editUser;
    } else {
        $error = 'The selected user account could not be found.';
    }
}

$isEditing = $formMode === 'update';

/* ---------- Accounts + statistics ---------- */

$users = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, u.role_id, u.is_active, r.name AS role
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    ORDER BY u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalAccounts    = count($users);
$activeAccounts   = 0;
$adminAccounts    = 0;

foreach ($users as $account) {
    $activeAccounts += (int) $account['is_active'] === 1 ? 1 : 0;
    $adminAccounts  += isAdminRoleName($account['role'] ?? '') ? 1 : 0;
}

$inactiveAccounts = $totalAccounts - $activeAccounts;

$statCards = [
    ['Total Accounts',    '♙', $totalAccounts,    'All registered accounts'],
    ['Active Accounts',   '✓', $activeAccounts,   'Accounts with access'],
    ['Inactive Accounts', '◷', $inactiveAccounts, 'Deactivated accounts'],
    ['Administrators',    '◆', $adminAccounts,    'Registered administrator accounts'],
];

$pageTitle = 'User Management';
require __DIR__ . '/../includes/header.php';
?>

<div class="admin-page">

    <?php if ($error): ?>
        <div class="alert error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="admin-stats-grid admin-user-stats">
        <?php foreach ($statCards as [$label, $icon, $value, $caption]): ?>
            <div class="admin-stat-card">
                <div class="admin-stat-top">
                    <span><?= e($label) ?></span>
                    <span class="admin-stat-icon" aria-hidden="true"><?= $icon ?></span>
                </div>
                <h3><?= number_format($value) ?></h3>
                <p><?= e($caption) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Add / Edit User -->
    <section class="form-card admin-user-form" id="account-form">

        <div class="card-heading">
            <span class="eyebrow"><?= $isEditing ? 'Account settings' : 'New account' ?></span>
            <h2><?= $isEditing ? 'Edit User Account' : 'Create New User' ?></h2>
            <p>
                <?= $isEditing
                    ? 'Update the selected user account information.'
                    : 'Register a new account in the help desk system.' ?>
            </p>
        </div>

        <form method="POST" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">

            <?php if ($isEditing): ?>
                <input type="hidden" name="user_id" value="<?= (int) $editingId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label>
                    First Name
                    <input type="text" name="first_name" required maxlength="100"
                           value="<?= e($formValues['first_name']) ?>" placeholder="Enter first name">
                </label>

                <label>
                    Last Name
                    <input type="text" name="last_name" required maxlength="100"
                           value="<?= e($formValues['last_name']) ?>" placeholder="Enter last name">
                </label>
            </div>

            <label>
                Email Address
                <input type="email" name="email" required maxlength="190" autocomplete="off"
                       value="<?= e($formValues['email']) ?>" placeholder="name@example.com">
            </label>

            <div class="form-row">
                <label>
                    Account Role
                    <select name="role_id" required>
                        <option value="">Select Role</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>"
                                <?= (string) $formValues['role_id'] === (string) $role['id'] ? 'selected' : '' ?>>
                                <?= e(role_label($role['name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?= $isEditing ? 'New Password (Optional)' : 'Password' ?>
                    <input type="password" name="password" minlength="8" autocomplete="new-password"
                           <?= $isEditing ? '' : 'required' ?>
                           placeholder="<?= $isEditing ? 'Leave blank to keep current password' : 'At least 8 characters' ?>">
                </label>
            </div>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" <?= (int) $formValues['is_active'] === 1 ? 'checked' : '' ?>>
                Active Account
            </label>

            <div class="form-actions">
                <button type="submit" class="btn primary">
                    <?= $isEditing ? 'Save Changes' : 'Create Account' ?>
                </button>

                <?php if ($isEditing): ?>
                    <a href="users.php" class="btn secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- Registered Users -->
    <section class="table-card admin-users-table-card">

        <div class="card-heading admin-users-heading">
            <div>
                <span class="eyebrow">Account directory</span>
                <h2>Registered Users</h2>
                <p>View and manage all accounts in the system.</p>
            </div>
            <span class="admin-user-count"><?= number_format($totalAccounts) ?> Accounts</span>
        </div>

        <div class="admin-table-search">
            <span aria-hidden="true">⌕</span>
            <input
                type="search"
                placeholder="Search name, email, role..."
                aria-label="Search registered users"
                data-table-search="#userTableBody"
                data-empty-message="#noUserResults"
            >
        </div>

        <div class="table-scroll">
            <table class="admin-users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Email Address</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="userTableBody">
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="6" class="admin-empty-state">No user accounts found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $account): ?>
                    <?php
                    $fullName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));
                    $isActive = (int) $account['is_active'] === 1;
                    $isSelf   = (int) $account['id'] === $currentId;
                    ?>
                    <tr data-searchable>
                        <td><span class="admin-user-id">#<?= (int) $account['id'] ?></span></td>

                        <td>
                            <div class="admin-user-info">
                                <div class="admin-user-avatar" aria-hidden="true">
                                    <?= e(strtoupper(substr($account['first_name'] ?? 'U', 0, 1))) ?>
                                </div>
                                <div>
                                    <strong><?= e($fullName) ?></strong>
                                    <?php if ($isSelf): ?><span>This is you</span><?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <td><?= e($account['email']) ?></td>

                        <td><span class="admin-role-badge"><?= e(role_label($account['role'])) ?></span></td>

                        <td>
                            <span class="admin-account-status <?= $isActive ? 'active' : 'inactive' ?>">
                                <span></span><?= $isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>

                        <td>
                            <div class="admin-user-actions">
                                <a href="users.php?edit=<?= (int) $account['id'] ?>#account-form" class="admin-edit-link"
                                   aria-label="Edit <?= e($fullName) ?>">Edit</a>

                                <?php if (!$isSelf): ?>
                                    <form method="POST" class="admin-toggle-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">

                                        <button
                                            type="submit"
                                            class="<?= $isActive ? 'admin-deactivate-link' : 'admin-activate-link' ?>"
                                            data-confirm="<?= $isActive ? 'Deactivate this account?' : 'Activate this account?' ?>"
                                            aria-label="<?= $isActive ? 'Deactivate' : 'Activate' ?> <?= e($fullName) ?>"
                                        >
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="admin-current-user">You</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="noUserResults" class="admin-empty-state" hidden>No matching accounts found.</div>
        </div>
    </section>

</div>

<?php
require __DIR__ . '/../includes/admin_table_search.php';
require __DIR__ . '/../includes/footer.php';