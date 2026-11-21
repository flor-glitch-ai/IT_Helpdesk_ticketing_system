<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    if ($first === '' || $email === '' || strlen($password) < 8 || !in_array($role, ['admin','it_staff','user'], true)) {
        $error = 'Complete the required fields. Password must be at least 8 characters.';
    } else {
        try {
            $r = $pdo->prepare('SELECT id FROM roles WHERE name=?'); $r->execute([$role]); $roleId = $r->fetchColumn();
            if (!$roleId) throw new RuntimeException('Role records are missing. Run the role setup SQL first.');
            $q = $pdo->prepare('INSERT INTO users (first_name,last_name,email,password,role_id,is_active) VALUES (?,?,?,?,?,1)');
            $q->execute([$first,$last,$email,password_hash($password,PASSWORD_DEFAULT),$roleId]);
            log_audit($pdo, current_user()['id'], 'User created', $email);
            flash('success', 'User account created.');
            redirect('users.php');
        } catch (Throwable $e) {
            $error = 'Could not create account. The email may already exist.';
            error_log($e->getMessage());
        }
    }
}
$users = $pdo->query("SELECT u.id,u.first_name,u.last_name,u.email,u.is_active,u.created_at,r.name role
    FROM users u LEFT JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC")->fetchAll();
$pageTitle = 'User management';
require __DIR__ . '/includes/header.php';
?>
<div class="two-column">
<div class="form-card"><div class="card-heading"><h2>Create account</h2><p class="muted">Add a requester or IT team member.</p></div>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<div class="form-row"><label>First name<input name="first_name" required></label><label>Last name<input name="last_name"></label></div>
<label>Email<input type="email" name="email" required></label>
<label>Temporary password<input type="password" name="password" minlength="8" required></label>
<label>Role<select name="role"><option value="user">User / Requester</option><option value="it_staff">IT Staff</option><option value="admin">Administrator</option></select></label>
<button class="btn primary">Create user</button></form></div>
<div class="form-card"><div class="card-heading"><h2>Account roles</h2></div><div class="role-info"><strong>Administrator</strong><p>Manage user accounts and oversee all tickets.</p></div><div class="role-info"><strong>IT Staff</strong><p>View, assign, and update support tickets.</p></div><div class="role-info"><strong>User / Requester</strong><p>Submit tickets and follow their own requests.</p></div></div>
</div>
<div class="section-heading"><div><h2>Registered users</h2><p class="muted"><?= count($users) ?> account(s)</p></div></div>
<div class="table-card"><div class="table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Account status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><strong><?= e($u['first_name'].' '.$u['last_name']) ?></strong></td><td><?= e($u['email']) ?></td><td><?= e(str_replace('_',' ',ucfirst($u['role'] ?? ''))) ?></td><td><?= $u['is_active'] ? 'Active' : 'Inactive' ?></td><td><?= e(date('M j, Y',strtotime($u['created_at']))) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
