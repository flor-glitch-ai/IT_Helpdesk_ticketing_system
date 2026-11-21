 
<?php
require_once __DIR__ . '/includes/bootstrap.php';

/**
 * Get the correct dashboard based on the user's role.
 */
function get_dashboard_path($user): ?string
{
    $role = strtolower(trim($user['role'] ?? ''));

    // Normalize role names.
    $role = str_replace([' ', '-'], '_', $role);

    $dashboards = [
        'admin' => '/erp-helpdesk/admin/dashboard.php',
        'it_staff' => '/erp-helpdesk/it_staff/dashboard.php',
        'user' => '/erp-helpdesk/user/dashboard.php'
    ];

    return $dashboards[$role] ?? null;
}

/**
 * Redirect an authenticated user to their dashboard.
 */
function redirect_user_to_dashboard($user): void
{
    $dashboard = get_dashboard_path($user);

    if ($dashboard !== null) {
        header('Location: ' . $dashboard);
        exit;
    }

    // Invalid role: clear the session instead of redirecting
    // repeatedly between login and dashboard.
    unset($_SESSION['user']);
}

// If already logged in, go directly to the correct dashboard.
$loggedInUser = current_user();

if ($loggedInUser) {
    $dashboard = get_dashboard_path($loggedInUser);

    if ($dashboard !== null) {
        redirect_user_to_dashboard($loggedInUser);
    }

    unset($_SESSION['user']);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare("
        SELECT
            u.id,
            CONCAT(u.first_name, ' ', u.last_name) AS full_name,
            u.email,
            u.password,
            r.name AS role
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.email = ?
          AND u.is_active = 1
        LIMIT 1
    ");

    $stmt->execute([$email]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {

        // Determine dashboard before creating the session.
        $dashboard = get_dashboard_path($user);

        if ($dashboard === null) {
            $error = 'Your account has an unrecognized role. Please contact your administrator.';
        } else {

            // Secure session after successful authentication.
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];

            log_audit(
                db(),
                (int) $user['id'],
                'Login',
                'User logged in.'
            );

            // Redirect according to account role.
            header('Location: ' . $dashboard);
            exit;
        }

    } else {
        $error = 'Incorrect email or password, or your account is inactive.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sign in | IT Help Desk</title>

    <link rel="stylesheet" href="/erp-helpdesk/assets/css/style.css">
</head>

<body class="auth-body">

<div class="auth-layout">

    <div class="auth-art">

        <div class="auth-brand">
            <span class="brand-mark">HD</span>
            IT Help Desk
        </div>

        <div class="auth-message">
            <span class="eyebrow light">IT SUPPORT PORTAL</span>

            <h1>Technology support, made simpler.</h1>

            <p>
                Submit requests, follow progress, and get back to what matters.
            </p>
        </div>

        <div class="auth-foot">
            A centralized space for IT assistance.
        </div>

    </div>

    <div class="auth-panel">

        <div class="auth-form-wrap">

            <span class="eyebrow">WELCOME BACK</span>

            <h2>Sign in to your account</h2>

            <p class="muted">
                Enter your account details to continue.
            </p>

            <?php if ($error): ?>
                <div class="alert error">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php show_flash(); ?>

            <form method="POST" class="form-stack">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <label>
                    Email address

                    <input
                        type="email"
                        name="email"
                        required
                        autocomplete="username"
                        placeholder="name@example.com"
                        value="<?= e($_POST['email'] ?? '') ?>"
                    >
                </label>

                <label>
                    Password

                    <input
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="Enter your password"
                    >
                </label>

                <button class="btn primary full" type="submit">
                    Sign in <span>→</span>
                </button>

            </form>

            <p class="auth-help">
                First time setup? Ask your administrator to create your account.
            </p>

        </div>

    </div>

</div>

<script src="/erp-helpdesk/assets/js/app.js"></script>

</body>
</html>
