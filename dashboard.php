<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$user = current_user();
$isStaff = is_staff();

if ($isStaff) {
    $stats = [
        'all' => (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn(),
        'open' => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Submitted','Reviewed','Assigned','In Progress','Pending User')")->fetchColumn(),
        'resolved' => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Resolved','Closed')")->fetchColumn(),
        'critical' => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE priority='Critical' AND status NOT IN ('Resolved','Closed')")->fetchColumn(),
    ];
    $stmt = $pdo->query("SELECT t.*, CONCAT(u.first_name,' ',u.last_name) requester
        FROM tickets t LEFT JOIN users u ON u.id=t.requester_id
        ORDER BY t.created_at DESC LIMIT 8");
} else {
    $q = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id=?");
    $q->execute([$user['id']]); $all = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id=? AND status NOT IN ('Resolved','Closed')");
    $q->execute([$user['id']]); $open = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE requester_id=? AND status IN ('Resolved','Closed')");
    $q->execute([$user['id']]); $resolved = (int)$q->fetchColumn();
    $stats = ['all'=>$all,'open'=>$open,'resolved'=>$resolved,'critical'=>0];
    $stmt = $pdo->prepare("SELECT t.*, CONCAT(u.first_name,' ',u.last_name) requester
        FROM tickets t LEFT JOIN users u ON u.id=t.requester_id
        WHERE t.requester_id=? ORDER BY t.created_at DESC LIMIT 8");
    $stmt->execute([$user['id']]);
}
$recent = $stmt->fetchAll();
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="welcome-row"><div><h2>Hello, <?= e($user['name']) ?>.</h2><p class="muted"><?= $isStaff ? 'Here is the current support activity.' : 'Here is an overview of your support requests.' ?></p></div><a class="btn primary" href="create_ticket.php">＋ Create ticket</a></div>
<div class="stats-grid">
    <div class="stat-card"><span class="stat-icon blue">▤</span><div><small>Total tickets</small><strong><?= $stats['all'] ?></strong></div></div>
    <div class="stat-card"><span class="stat-icon amber">◷</span><div><small>Active tickets</small><strong><?= $stats['open'] ?></strong></div></div>
    <div class="stat-card"><span class="stat-icon green">✓</span><div><small>Resolved / Closed</small><strong><?= $stats['resolved'] ?></strong></div></div>
    <?php if ($isStaff): ?><div class="stat-card"><span class="stat-icon red">!</span><div><small>Critical unresolved</small><strong><?= $stats['critical'] ?></strong></div></div><?php endif; ?>
</div>
<div class="section-heading"><div><h2>Recent tickets</h2><p class="muted">Latest support requests in the system</p></div><a class="text-link" href="tickets.php">View all tickets →</a></div>
<div class="table-card"><div class="table-scroll"><table><thead><tr><th>Ticket</th><th>Subject</th><?php if ($isStaff): ?><th>Requester</th><?php endif; ?><th>Priority</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
<?php if (!$recent): ?><tr><td colspan="7" class="empty">No tickets yet. Create the first support ticket.</td></tr><?php endif; ?>
<?php foreach ($recent as $t): ?><tr>
<td class="ticket-no"><?= e($t['ticket_number']) ?></td><td><strong><?= e($t['title']) ?></strong><small class="table-sub"><?= e($t['category'] ?? '') ?></small></td>
<?php if ($isStaff): ?><td><?= e($t['requester'] ?? 'Unknown') ?></td><?php endif; ?>
<td><span class="priority <?= strtolower(e($t['priority'])) ?>"><?= e($t['priority']) ?></span></td>
<td><span class="status"><?= e($t['status']) ?></span></td><td><?= e(date('M j, Y', strtotime($t['created_at']))) ?></td><td><a class="text-link" href="ticket_view.php?id=<?= (int)$t['id'] ?>">Open</a></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
