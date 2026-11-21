<?php
require_once __DIR__ . '/includes/bootstrap.php';

/*
 * Run this once after importing the schema.
 * Delete this file after creating the first administrator.
 */
$pdo = db();
$adminCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"
)->fetchColumn();

$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminCount === 0) {
    verify_csrf();
    $name = trim($_POST['full_name'] ?? '');
    $parts = preg_split('/\s+/', $name, 2);
    $first = $parts[0] ?? '';
    $last = $parts[1] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($first === '' || $email === '' || strlen($password) < 8) {
        $error = 'Enter a name, valid email, and password with at least 8 characters.';
    } else {
        try {
            $pdo->beginTransaction();
            foreach (['admin', 'it_staff', 'user'] as $roleName) {
                $checkRole = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
                $checkRole->execute([$roleName]);
                if (!$checkRole->fetchColumn()) {
                    $insertRole = $pdo->prepare('INSERT INTO roles (name) VALUES (?)');
                    $insertRole->execute([$roleName]);
                }
            }
            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
            $stmt = $pdo->prepare(
                'INSERT INTO users (first_name, last_name, email, password, role_id, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$first, $last, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
            $pdo->commit();
            $done = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Could not create administrator. Check whether the email already exists and verify the database schema.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Initial Admin Setup</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-body"><div class="auth-panel standalone"><div class="auth-form-wrap">
<h2>Initial administrator setup</h2>
<?php if ($done): ?>
<div class="alert success">Administrator created. Delete setup_admin.php from your project folder, then sign in.</div><a class="btn primary full" href="login.php">Go to sign in</a>
<?php elseif ($adminCount > 0): ?>
<div class="alert error">An administrator already exists. Delete setup_admin.php for security.</div><a href="login.php">Go to sign in</a>
<?php else: ?>
<p class="muted">Create the first administrator account. This page should only be used once.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-stack">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label>Full name<input name="full_name" required></label>
<label>Email<input type="email" name="email" required></label>
<label>Password (minimum 8 characters)<input type="password" name="password" minlength="8" required></label>
<button class="btn primary full">Create administrator</button>
</form>
<?php endif; ?></div></div></body></html>
