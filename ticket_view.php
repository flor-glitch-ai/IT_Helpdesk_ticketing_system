<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT t.*, CONCAT(u.first_name,' ',u.last_name) requester,
    CONCAT(a.first_name,' ',a.last_name) assignee
    FROM tickets t LEFT JOIN users u ON u.id=t.requester_id
    LEFT JOIN users a ON a.id=t.assigned_to WHERE t.id=?");
$stmt->execute([$id]); $ticket = $stmt->fetch();
if (!$ticket || (!is_staff() && (int)$ticket['requester_id'] !== (int)current_user()['id'])) {
    http_response_code(404); exit('Ticket not found.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'comment') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment !== '') {
            $q = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, comment) VALUES (?, ?, ?)');
            $q->execute([$id, current_user()['id'], $comment]);
            if ((int)$ticket['requester_id'] !== (int)current_user()['id']) {
                notify_user($pdo, (int)$ticket['requester_id'], 'Ticket update', 'A new comment was added to ' . $ticket['ticket_number']);
            }
            flash('success', 'Comment added.');
            redirect('ticket_view.php?id=' . $id);
        }
        $error = 'Comment cannot be empty.';
    } elseif ($action === 'update' && is_staff()) {
        $newStatus = $_POST['status'] ?? $ticket['status'];
        $newPriority = $_POST['priority'] ?? $ticket['priority'];
        $assigned = ($_POST['assigned_to'] ?? '') === '' ? null : (int)$_POST['assigned_to'];
        if (!in_array($newStatus, ticket_statuses(), true) || !in_array($newPriority, priorities(), true)) {
            $error = 'Invalid status or priority.';
        } else {
            if ($newStatus !== $ticket['status']) {
                log_history($pdo, $id, current_user()['id'], 'status', $ticket['status'], $newStatus);
            }
            if ($newPriority !== $ticket['priority']) {
                log_history($pdo, $id, current_user()['id'], 'priority', $ticket['priority'], $newPriority);
            }
            if ((string)$assigned !== (string)($ticket['assigned_to'] ?? '')) {
                log_history($pdo, $id, current_user()['id'], 'assigned_to', $ticket['assigned_to'] === null ? null : (string)$ticket['assigned_to'], $assigned === null ? null : (string)$assigned);
            }
            $q = $pdo->prepare('UPDATE tickets SET status=?, priority=?, assigned_to=? WHERE id=?');
            $q->execute([$newStatus, $newPriority, $assigned, $id]);
            if ($assigned) {
                notify_user($pdo, $assigned, 'Ticket assigned', 'You have been assigned ticket ' . $ticket['ticket_number']);
            }
            log_audit($pdo, current_user()['id'], 'Ticket updated', $ticket['ticket_number']);
            flash('success', 'Ticket updated.');
            redirect('ticket_view.php?id=' . $id);
        }
    }
}

$cq = $pdo->prepare("SELECT c.*, CONCAT(u.first_name,' ',u.last_name) author, r.name role
    FROM ticket_comments c JOIN users u ON u.id=c.user_id
    LEFT JOIN roles r ON r.id=u.role_id WHERE c.ticket_id=? ORDER BY c.created_at ASC");
$cq->execute([$id]); $comments = $cq->fetchAll();

$hq = $pdo->prepare("SELECT h.*, CONCAT(u.first_name,' ',u.last_name) actor
    FROM ticket_history h LEFT JOIN users u ON u.id=h.changed_by
    WHERE h.ticket_id=? ORDER BY h.created_at DESC");
$hq->execute([$id]); $history = $hq->fetchAll();

$staff = $pdo->query("SELECT u.id, CONCAT(u.first_name,' ',u.last_name) name
    FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name IN ('admin','it_staff') AND u.is_active=1 ORDER BY name")->fetchAll();

$pageTitle = 'Ticket details';
require __DIR__ . '/includes/header.php';
?>
<div class="back-row"><a class="text-link" href="tickets.php">← Back to tickets</a><span class="ticket-no"><?= e($ticket['ticket_number']) ?></span></div>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="ticket-layout">
    <div class="main-column">
        <div class="form-card">
            <div class="ticket-title-row"><div><span class="eyebrow"><?= e($ticket['category'] ?? 'Support request') ?></span><h2><?= e($ticket['title']) ?></h2><p class="muted">Submitted by <?= e($ticket['requester'] ?? 'Unknown') ?> · <?= e(date('M j, Y g:i A', strtotime($ticket['created_at']))) ?></p></div><span class="priority <?= strtolower(e($ticket['priority'])) ?>"><?= e($ticket['priority']) ?></span></div>
            <div class="description"><?= nl2br(e($ticket['description'])) ?></div>
        </div>
        <div class="form-card">
            <div class="card-heading"><h2>Conversation</h2><p class="muted">Comments and updates for this ticket</p></div>
            <div class="comment-list">
            <?php if (!$comments): ?><p class="empty">No comments yet.</p><?php endif; ?>
            <?php foreach ($comments as $c): ?><div class="comment"><div class="comment-avatar"><?= e(strtoupper(substr($c['author'],0,1))) ?></div><div class="comment-body"><div class="comment-meta"><strong><?= e($c['author']) ?></strong><span><?= e($c['role'] ?? '') ?> · <?= e(date('M j, g:i A', strtotime($c['created_at']))) ?></span></div><p><?= nl2br(e($c['comment'])) ?></p></div></div><?php endforeach; ?>
            </div>
            <form method="post" class="form-stack comment-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="comment"><label>Add a comment<textarea name="comment" rows="3" required placeholder="Write an update or ask a question..."></textarea></label><div class="form-actions"><button class="btn primary">Post comment</button></div></form>
        </div>
        <div class="form-card"><div class="card-heading"><h2>Activity history</h2></div>
            <?php if (!$history): ?><p class="muted">No recorded changes.</p><?php endif; ?>
            <div class="history-list"><?php foreach ($history as $h): ?><div class="history-item"><span class="history-dot"></span><div><strong><?= e($h['field_changed']) ?> changed</strong><p><?= e($h['old_value'] ?? '—') ?> → <?= e($h['new_value'] ?? '—') ?></p><small><?= e($h['actor'] ?? 'System') ?> · <?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></small></div></div><?php endforeach; ?></div>
        </div>
    </div>
    <aside class="side-column">
        <div class="form-card"><h3>Ticket information</h3><div class="detail-pair"><span>Status</span><strong><?= e($ticket['status']) ?></strong></div><div class="detail-pair"><span>Priority</span><strong><?= e($ticket['priority']) ?></strong></div><div class="detail-pair"><span>Assigned to</span><strong><?= e($ticket['assignee'] ?? 'Unassigned') ?></strong></div><div class="detail-pair"><span>Last updated</span><strong><?= e(date('M j, Y', strtotime($ticket['updated_at']))) ?></strong></div></div>
        <?php if (is_staff()): ?><div class="form-card"><h3>Manage ticket</h3><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update">
            <label>Assign to<select name="assigned_to"><option value="">Unassigned</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$ticket['assigned_to']===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
            <label>Status<select name="status"><?php foreach (ticket_statuses() as $s): ?><option <?= $ticket['status']===$s?'selected':'' ?> value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></label>
            <label>Priority<select name="priority"><?php foreach (priorities() as $p): ?><option <?= $ticket['priority']===$p?'selected':'' ?> value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?></select></label>
            <button class="btn primary full">Save changes</button></form></div><?php endif; ?>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
