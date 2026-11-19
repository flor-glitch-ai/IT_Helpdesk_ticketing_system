 
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

<style>
/* PROFILE PAGE */

.profile-page {
    max-width: 1100px;
    margin: 0 auto;
}

.profile-intro {
    margin-bottom: 25px;
}

.profile-intro h2 {
    margin-bottom: 6px;
    color: #172b4d;
}

.profile-intro p {
    color: #718096;
    margin: 0;
}

.profile-banner {
    background: linear-gradient(120deg, #102b50, #1d4778);
    border-radius: 14px;
    padding: 28px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
    box-shadow: 0 8px 22px rgba(16, 43, 80, .12);
}

.profile-avatar {
    width: 76px;
    height: 76px;
    min-width: 76px;
    border-radius: 50%;
    background: #ffffff;
    color: #173b67;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 29px;
    font-weight: 700;
    border: 4px solid rgba(255,255,255,.25);
}

.profile-banner-info h2 {
    margin: 0 0 6px;
    color: #fff;
    font-size: 23px;
}

.profile-banner-info p {
    margin: 0 0 10px;
    color: #d5e3f5;
    font-size: 13px;
}

.profile-role {
    display: inline-block;
    background: rgba(255,255,255,.15);
    color: #fff;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .5px;
}

.profile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
    align-items: start;
}

.profile-card {
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 3px 12px rgba(20, 40, 70, .04);
    min-width: 0;
}

.profile-card-header {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    padding-bottom: 20px;
    margin-bottom: 20px;
    border-bottom: 1px solid #edf1f6;
}

.profile-card-icon {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 10px;
    background: #edf4fc;
    color: #24558c;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
}

.profile-card-header h3 {
    margin: 0 0 5px;
    color: #203653;
    font-size: 17px;
}

.profile-card-header p {
    margin: 0;
    font-size: 12px;
    color: #8794a5;
}

.profile-form-group {
    margin-bottom: 17px;
}

.profile-form-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #42536a;
}

.profile-form-group input {
    width: 100%;
    box-sizing: border-box;
    min-height: 43px;
    padding: 11px 13px;
    border: 1px solid #dce4ee;
    border-radius: 7px;
    background: #fbfcfe;
    color: #25364d;
    font-size: 13px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}

.profile-form-group input:focus {
    border-color: #386da8;
    box-shadow: 0 0 0 3px rgba(56,109,168,.10);
    background: #fff;
}

.profile-hint {
    font-size: 11px;
    color: #929eac;
    margin-top: 6px;
}

.profile-submit {
    width: 100%;
    justify-content: center;
    margin-top: 5px;
}

.profile-alert {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}

.profile-alert.error {
    background: #fff0f0;
    border: 1px solid #ffd4d4;
    color: #b42318;
}

.profile-alert.success {
    background: #edf9f1;
    border: 1px solid #c8edd3;
    color: #207344;
}

.profile-security-note {
    margin-top: 17px;
    padding: 12px;
    background: #f4f7fb;
    border-radius: 7px;
    color: #718096;
    font-size: 11px;
    line-height: 1.6;
}

@media (max-width: 850px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 500px) {
    .profile-banner {
        padding: 20px;
        gap: 14px;
    }

    .profile-avatar {
        width: 60px;
        height: 60px;
        min-width: 60px;
        font-size: 23px;
    }

    .profile-banner-info h2 {
        font-size: 18px;
    }

    .profile-card {
        padding: 18px;
    }
}
</style>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
