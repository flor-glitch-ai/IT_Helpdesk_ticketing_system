 
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$pdo = db();
$user = current_user();

$userId = (int) $user['id'];

$role = strtolower(str_replace(
    [' ', '-'],
    '_',
    trim($user['role'] ?? '')
));

if ($role !== 'user') {
    http_response_code(403);
    exit('Access denied.');
}

$error = '';
$success = '';

$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, password
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    http_response_code(404);
    exit('User account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($firstName === '' || $lastName === '') {

            $error = 'First name and last name are required.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = 'Please enter a valid email address.';

        } else {

            $checkStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                AND id != ?
                LIMIT 1
            ");

            $checkStmt->execute([$email, $userId]);

            if ($checkStmt->fetch()) {

                $error = 'This email address is already being used.';

            } else {

                $updateStmt = $pdo->prepare("
                    UPDATE users
                    SET first_name = ?,
                        last_name = ?,
                        email = ?
                    WHERE id = ?
                ");

                $updateStmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    $userId
                ]);

                $_SESSION['user']['name'] =
                    $firstName . ' ' . $lastName;

                $_SESSION['user']['email'] = $email;

                $success = 'Your profile information has been updated.';
            }
        }

    } elseif ($action === 'change_password') {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPassword, $profile['password'])) {

            $error = 'Your current password is incorrect.';

        } elseif (strlen($newPassword) < 8) {

            $error = 'Your new password must be at least 8 characters.';

        } elseif ($newPassword !== $confirmPassword) {

            $error = 'The new passwords do not match.';

        } elseif ($newPassword === $currentPassword) {

            $error = 'Your new password must be different from your current password.';

        } else {

            $hashedPassword = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $updateStmt = $pdo->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $hashedPassword,
                $userId
            ]);

            $success = 'Your password has been changed successfully.';
        }

    } else {

        $error = 'Invalid request.';
    }

    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'My Profile';

require __DIR__ . '/../includes/header.php';

$fullName = trim(
    $profile['first_name'] . ' ' . $profile['last_name']
);

$initial = strtoupper(substr($profile['first_name'], 0, 1));
?>

<div class="user-page">

<div class="profile-page">

    <div class="profile-intro">
        <h2>Account Settings</h2>
        <p>Manage your personal information and keep your account secure.</p>
    </div>

    <?php if ($error): ?>
        <div class="profile-alert error">
            ⚠ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="profile-alert success">
            ✓ <?= e($success) ?>
        </div>
    <?php endif; ?>

    <!-- PROFILE BANNER -->

    <div class="profile-banner">

        <div class="profile-avatar">
            <?= e($initial) ?>
        </div>

        <div class="profile-banner-info">

            <h2><?= e($fullName) ?></h2>

            <p><?= e($profile['email']) ?></p>

            <span class="profile-role">
                IT HELP DESK USER
            </span>

        </div>

    </div>

    <!-- PROFILE FORMS -->

    <div class="profile-grid">

        <!-- PERSONAL INFORMATION -->

        <div class="profile-card">

            <div class="profile-card-header">

                <div class="profile-card-icon">♙</div>

                <div>
                    <h3>Personal Information</h3>
                    <p>Update your basic account information.</p>
                </div>

            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update_profile"
                >

                <div class="profile-form-group">

                    <label for="first_name">First Name</label>

                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        maxlength="100"
                        required
                        value="<?= e($profile['first_name']) ?>"
                    >

                </div>

                <div class="profile-form-group">

                    <label for="last_name">Last Name</label>

                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        maxlength="100"
                        required
                        value="<?= e($profile['last_name']) ?>"
                    >

                </div>

                <div class="profile-form-group">

                    <label for="email">Email Address</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="255"
                        required
                        value="<?= e($profile['email']) ?>"
                    >

                    <div class="profile-hint">
                        Your email address is used for account identification.
                    </div>

                </div>

                <button
                    type="submit"
                    class="btn primary profile-submit"
                >
                    Save Changes
                </button>

            </form>

        </div>

        <!-- CHANGE PASSWORD -->

        <div class="profile-card">

            <div class="profile-card-header">

                <div class="profile-card-icon">♧</div>

                <div>
                    <h3>Change Password</h3>
                    <p>Update your password to protect your account.</p>
                </div>

            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="change_password"
                >

                <div class="profile-form-group">

                    <label for="current_password">
                        Current Password
                    </label>

                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        required
                        autocomplete="current-password"
                    >

                </div>

                <div class="profile-form-group">

                    <label for="new_password">
                        New Password
                    </label>

                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                    >

                    <div class="profile-hint">
                        Use at least 8 characters.
                    </div>

                </div>

                <div class="profile-form-group">

                    <label for="confirm_password">
                        Confirm New Password
                    </label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                    >

                </div>

                <button
                    type="submit"
                    class="btn primary profile-submit"
                >
                    Change Password
                </button>

            </form>

            <div class="profile-security-note">
                Your password is securely stored using password hashing.
                Never share your password with anyone.
            </div>

        </div>

    </div>

</div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
