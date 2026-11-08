<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare(
        "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.email,
                u.password, r.name AS role
         FROM users u LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? AND u.is_active = 1 LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'] ?? 'user'
        ];
        log_audit(db(), (int)$user['id'], 'Login', 'User logged in.');
        redirect('dashboard.php');
    }
    $error = 'Incorrect email or password, or your account is inactive.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in | IT Help Desk</title><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<div class="auth-layout">
    <div class="auth-art">
        <div class="auth-brand"><span class="brand-mark">HD</span> IT Help Desk</div>
        <div class="auth-message"><span class="eyebrow light">IT SUPPORT PORTAL</span><h1>Technology support, made simpler.</h1><p>Submit requests, follow progress, and get back to what matters.</p></div>
        <div class="auth-foot">A centralized space for IT assistance.</div>
    </div>
    <div class="auth-panel">
        <div class="auth-form-wrap">
            <span class="eyebrow">WELCOME BACK</span><h2>Sign in to your account</h2><p class="muted">Enter your account details to continue.</p>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <?php show_flash(); ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email address<input type="email" name="email" required autocomplete="username" placeholder="name@example.com"></label>
                <label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></label>
                <button class="btn primary full" type="submit">Sign in <span>→</span></button>
            </form>
            <p class="auth-help">First time setup? Ask your administrator to create your account.</p>
        </div>
    </div>
</div>
<script src="assets/js/app.js"></script>
</body></html>
