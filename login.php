
<?php

require_once __DIR__ . '/includes/bootstrap.php';

/**
 * Get the correct dashboard based on the user's role.
 */
function get_dashboard_path($user): ?string
{
    $role = strtolower(trim($user['role'] ?? ''));
    $role = str_replace([' ', '-'], '_', $role);

    $dashboards = [
        'admin'    => '/erp-helpdesk/admin/dashboard.php',
        'it_staff' => '/erp-helpdesk/it_staff/dashboard.php',
        'user'     => '/erp-helpdesk/user/dashboard.php'
    ];

    return $dashboards[$role] ?? null;
}

/**
 * Redirect authenticated users to their dashboard.
 */
function redirect_user_to_dashboard($user): void
{
    $dashboard = get_dashboard_path($user);

    if ($dashboard !== null) {
        header('Location: ' . $dashboard);
        exit;
    }

    unset($_SESSION['user']);
}

/* Redirect logged-in users. */
$loggedInUser = current_user();

if ($loggedInUser) {
    redirect_user_to_dashboard($loggedInUser);
}

$error = '';

/* PROCESS LOGIN */
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

        $dashboard = get_dashboard_path($user);

        if ($dashboard === null) {

            $error = 'Invalid account role. Contact your administrator.';

        } else {

            /* Regenerate session ID after successful authentication. */
            session_regenerate_id(true);

            /* Store authenticated user information. */
            $_SESSION['user'] = [
                'id'    => (int) $user['id'],
                'name'  => $user['full_name'],
                'email' => $user['email'],
                'role'  => $user['role']
            ];

            /* Record login activity. */
            log_audit(
                db(),
                (int) $user['id'],
                'Login',
                'User logged in.'
            );

            /* Redirect according to account role. */
            header('Location: ' . $dashboard);
            exit;
        }

    } else {

        $error = 'Incorrect email or password, or inactive account.';

    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color"
          content="#102744">

    <title>Sign In | IT Help Desk</title>

    <!-- LOGIN STYLESHEETS -->
    <link rel="stylesheet"
          href="/erp-helpdesk/assets/css/base.css">

    <link rel="stylesheet"
          href="/erp-helpdesk/assets/css/login.css">

</head>

<body class="auth-body">

<div class="auth-layout">

    <!-- BACKGROUND AND BRANDING -->
    <section class="auth-art"
             aria-label="IT Help Desk branding">

        <div class="auth-brand">

            <span class="brand-mark">HD</span>

            <div class="brand-text">

                <strong>IT HELP DESK</strong>

                <small>
                    TICKETING MANAGEMENT SYSTEM
                </small>

            </div>

        </div>

    </section>


    <!-- LOGIN SECTION -->
    <main class="auth-panel">

        <div class="auth-form-wrap">

            <!-- LOGIN HEADING -->
            <div class="login-heading">

                <span class="eyebrow">
                    WELCOME BACK
                </span>

                <h2>Sign In</h2>

                <p>
                    Sign in to continue.
                </p>

            </div>


            <!-- ERROR MESSAGE -->
            <?php if ($error): ?>

                <div class="alert error"
                     role="alert"
                     aria-live="polite">

                    <span class="alert-icon">!</span>

                    <?= e($error) ?>

                </div>

            <?php endif; ?>


            <!-- FLASH MESSAGE -->
            <?php show_flash(); ?>


            <!-- LOGIN FORM -->
            <form method="POST"
                  action=""
                  class="form-stack">

                <!-- CSRF TOKEN -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >


                <!-- EMAIL FIELD -->
                <div class="auth-field">

                    <label for="email">
                        Email Address
                    </label>

                    <div class="input-wrapper">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true">

                            <rect
                                x="3"
                                y="5"
                                width="18"
                                height="14"
                                rx="2"
                            />

                            <path d="m3 7 9 6 9-6"/>

                        </svg>

                        <input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autocomplete="username"
                            placeholder="Enter your email"
                            value="<?= e($_POST['email'] ?? '') ?>"
                        >

                    </div>

                </div>


                <!-- PASSWORD FIELD -->
                <div class="auth-field">

                    <label for="password">
                        Password
                    </label>

                    <div class="password-field input-wrapper">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true">

                            <rect
                                x="3"
                                y="10"
                                width="18"
                                height="11"
                                rx="2"
                            />

                            <path d="M7 10V7a5 5 0 0 1 10 0v3"/>

                        </svg>

                        <input
                            id="password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >

                        <button
                            class="password-toggle"
                            type="button"
                            data-toggle-password
                            aria-controls="password"
                            aria-label="Show password"
                            aria-pressed="false">

                            Show

                        </button>

                    </div>

                </div>


                <!-- SIGN IN BUTTON -->
                <button
                    class="btn primary full login-submit"
                    type="submit">

                    <span>Sign In</span>

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true">

                        <path d="M5 12h14"/>
                        <path d="m13 6 6 6-6 6"/>

                    </svg>

                </button>

            </form>


            <!-- LOGIN FOOTER -->
            <div class="login-bottom">

                <div class="secure-note">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true">

                        <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/>

                        <path d="m9 12 2 2 4-4"/>

                    </svg>

                    <span>Secure Login</span>

                </div>

                <p>
                    Need an account? Contact your administrator.
                </p>

            </div>

        </div>


        <!-- COPYRIGHT -->
        <div class="auth-copyright">

            &copy; <?= date('Y') ?> IT Help Desk.
            All rights reserved.

        </div>

    </main>

</div>


<!-- JAVASCRIPT -->
<script src="/erp-helpdesk/assets/js/app.js"></script>

</body>
</html>
