<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$params = [];
$sql = "SELECT t.*, CONCAT(u.first_name,' ',u.last_name) requester,
        CONCAT(a.first_name,' ',a.last_name) assignee
        FROM tickets t
        LEFT JOIN users u ON u.id=t.requester_id
        LEFT JOIN users a ON a.id=t.assigned_to";
if (!is_staff()) {
    $sql .= " WHERE t.requester_id=?";
    $params[] = current_user()['id'];
}
$sql .= " ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $tickets = $stmt->fetchAll();
$pageTitle = 'Tickets';
require __DIR__ . '/includes/header.php';
?>
<div class="section-heading"><div><h2><?= is_staff() ? 'All support tickets' : 'My support tickets' ?></h2><p class="muted"><?= count($tickets) ?> ticket(s) found</p></div><a class="btn primary" href="create_ticket.php">＋ Create ticket</a></div>
<div class="table-card"><div class="table-scroll"><table><thead><tr><th>Ticket ID</th><th>Subject</th><?php if (is_staff()): ?><th>Requester</th><th>Assigned to</th><?php endif; ?><th>Priority</th><th>Status</th><th>Last update</th><th></th></tr></thead><tbody>
<?php if (!$tickets): ?><tr><td colspan="8" class="empty">No tickets found.</td></tr><?php endif; ?>
<?php foreach ($tickets as $t): ?><tr>
<td class="ticket-no"><?= e($t['ticket_number']) ?></td><td><strong><?= e($t['title']) ?></strong><small class="table-sub"><?= e($t['category'] ?? '') ?></small></td>
<?php if (is_staff()): ?><td><?= e($t['requester'] ?? '-') ?></td><td><?= e($t['assignee'] ?? 'Unassigned') ?></td><?php endif; ?>
<td><span class="priority <?= strtolower(e($t['priority'])) ?>"><?= e($t['priority']) ?></span></td><td><span class="status"><?= e($t['status']) ?></span></td>
<td><?= e(date('M j, Y g:i A', strtotime($t['updated_at']))) ?></td><td><a class="text-link" href="ticket_view.php?id=<?= (int)$t['id'] ?>">Details →</a></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
