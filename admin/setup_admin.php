
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();

$error = '';
$setupAllowed = false;

// Check whether an active administrator already exists.
function hasActiveAdmin(PDO $pdo): bool
{
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE LOWER(r.name) = 'admin'
          AND u.is_active = 1
    ");

    return (int)$stmt->fetchColumn() > 0;
}

// Stop setup if an active administrator already exists.
if (hasActiveAdmin($pdo)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Setup | IT Help Desk</title>
        <link rel="stylesheet" href="/erp-helpdesk/assets/css/style.css">
    </head>
    <body class="auth-body">
        <div class="form-card narrow" style="margin:80px auto; max-width:500px;">
            <h2>Administrator Already Exists</h2>
            <p class="muted">
                An active administrator account is already registered.
                Initial setup is no longer available.
            </p>
            <a href="/erp-helpdesk/login.php" class="btn primary">
                Go to Login
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Find the administrator role.
$roleStmt = $pdo->prepare("
    SELECT id
    FROM roles
    WHERE LOWER(name) = 'admin'
    LIMIT 1
");

$roleStmt->execute();
$adminRoleId = $roleStmt->fetchColumn();

if (!$adminRoleId) {
    http_response_code(500);
    exit('Admin role was not found. Please check your roles table.');
}

$setupAllowed = true;

// Process initial administrator registration.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    // Recheck before creating the account.
    if (hasActiveAdmin($pdo)) {
        $error = 'An active administrator account already exists.';
    } else {

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            $firstName === '' ||
            $lastName === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = 'Please enter a valid first name, last name, and email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must contain at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {

            // Check whether email is already registered.
            $emailCheck = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $emailCheck->execute([$email]);

            if ($emailCheck->fetchColumn()) {
                $error = 'This email address is already registered.';
            } else {

                try {

                    $pdo->beginTransaction();

                    // Recheck inside the transaction.
                    if (hasActiveAdmin($pdo)) {
                        throw new RuntimeException(
                            'An active administrator account already exists.'
                        );
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO users (
                            first_name,
                            last_name,
                            email,
                            password,
                            role_id,
                            is_active
                        )
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");

                    $stmt->execute([
                        $firstName,
                        $lastName,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $adminRoleId
                    ]);

                    $adminId = (int)$pdo->lastInsertId();

                    $pdo->commit();

                    // Log in the newly created administrator.
                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'id' => $adminId,
                        'name' => $firstName . ' ' . $lastName,
                        'email' => $email,
                        'role' => 'admin'
                    ];

                    // Redirect to Admin Dashboard.
                    header('Location: /erp-helpdesk/admin/dashboard.php');
                    exit;

                } catch (Throwable $exception) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log($exception->getMessage());

                    $error = 'Unable to complete administrator setup. Please check the server error log.';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Initial Admin Setup | IT Help Desk</title>

    <link rel="stylesheet" href="/erp-helpdesk/assets/css/style.css">

    <style>
        .setup-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 16px;
            background: #f3f5f9;
        }

        .setup-card {
            width: 100%;
            max-width: 540px;
            background: #fff;
            padding: 38px;
            border-radius: 12px;
            box-shadow: 0 12px 35px rgba(15, 35, 65, .09);
        }

        .setup-brand {
            color: #142b4a;
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 25px;
        }

        .setup-brand span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin-right: 8px;
            background: #142b4a;
            color: #fff;
            border-radius: 8px;
        }

        .setup-card h1 {
            color: #142b4a;
            font-size: 27px;
            margin-bottom: 8px;
        }

        .setup-card .muted {
            margin-bottom: 25px;
        }

        .setup-card .form-stack {
            display: flex;
            flex-direction: column;
            gap: 17px;
        }

        .setup-card input {
            width: 100%;
        }

        .setup-note {
            margin-top: 20px;
            font-size: 12px;
            color: #777;
            line-height: 1.6;
        }

        @media (max-width: 600px) {
            .setup-card {
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

<div class="setup-wrapper">

    <div class="setup-card">

        <div class="setup-brand">
            <span>HD</span>
            IT Help Desk
        </div>

        <span class="eyebrow">INITIAL CONFIGURATION</span>

        <h1>Create Administrator Account</h1>

        <p class="muted">
            Set up the first administrator account for your IT Help Desk system.
        </p>

        <?php if ($error): ?>
            <div class="alert error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-stack">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <label>
                First Name

                <input
                    type="text"
                    name="first_name"
                    required
                    maxlength="100"
                    value="<?= e($_POST['first_name'] ?? '') ?>"
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
                    value="<?= e($_POST['last_name'] ?? '') ?>"
                    placeholder="Enter last name"
                >
            </label>

            <label>
                Email Address

                <input
                    type="email"
                    name="email"
                    required
                    value="<?= e($_POST['email'] ?? '') ?>"
                    placeholder="admin@example.com"
                >
            </label>

            <label>
                Password

                <input
                    type="password"
                    name="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="At least 8 characters"
                >
            </label>

            <label>
                Confirm Password

                <input
                    type="password"
                    name="confirm_password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Re-enter your password"
                >
            </label>

            <button type="submit" class="btn primary full">
                Create Administrator Account
            </button>

        </form>

        <p class="setup-note">
            This page is only for initial administrator setup.
            Once an active administrator exists, this setup page will be blocked.
        </p>

    </div>

</div>

</body>
</html>
