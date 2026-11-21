<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'Other';
    $priority = $_POST['priority'] ?? 'Medium';

    if ($title === '' || $description === '' || !in_array($category, ticket_categories(), true) || !in_array($priority, priorities(), true)) {
        $error = 'Complete all fields and select valid category and priority values.';
    } else {
        $ticketNumber = 'HD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare(
            "INSERT INTO tickets (ticket_number, title, description, category, priority, status, requester_id)
             VALUES (?, ?, ?, ?, ?, 'Submitted', ?)"
        );
        $stmt->execute([$ticketNumber, $title, $description, $category, $priority, current_user()['id']]);
        $ticketId = (int)$pdo->lastInsertId();
        log_history($pdo, $ticketId, current_user()['id'], 'status', null, 'Submitted');
        log_audit($pdo, current_user()['id'], 'Ticket created', $ticketNumber);
        flash('success', 'Your ticket has been submitted successfully.');
        redirect('ticket_view.php?id=' . $ticketId);
    }
}
$pageTitle = 'Create ticket';
require __DIR__ . '/includes/header.php';
?>
<div class="form-card narrow">
    <div class="card-heading"><h2>Submit a support request</h2><p class="muted">Describe the issue so the IT team can assist you.</p></div>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Subject<input name="title" maxlength="255" required placeholder="Example: My computer cannot connect to Wi-Fi"></label>
        <div class="form-row">
            <label>Category<select name="category"><?php foreach (ticket_categories() as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?></select></label>
            <label>Priority<select name="priority"><?php foreach (priorities() as $p): ?><option value="<?= e($p) ?>" <?= $p==='Medium'?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?></select></label>
        </div>
        <label>Issue description<textarea name="description" rows="7" required placeholder="Explain what happened, when it started, and any error message you saw."></textarea></label>
        <div class="form-actions"><a class="btn secondary" href="tickets.php">Cancel</a><button class="btn primary">Submit ticket</button></div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
